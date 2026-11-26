<?php

declare(strict_types=1);

namespace Fisharebest\Webtrees\Module;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Http\Exceptions\HttpForbiddenException;
use Fisharebest\Webtrees\Http\Exceptions\HttpNotFoundException;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Session;
use Fisharebest\Webtrees\View;
use Fisharebest\Webtrees\Http\RequestHandlers\ModuleAction;
use Fisharebest\Webtrees\Http\Controllers\TreePage;
use Fisharebest\Webtrees\Validator;
use Illuminate\Support\Collection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function route;
use function redirect;
use function random_bytes;
use function hash;
use function bin2hex;
use function time;

class FamilyVisitorLinks extends AbstractModule implements ModuleConfigInterface, ModuleCustomInterface
{
    use ModuleConfigTrait;
    use ModuleCustomTrait;

    private const TABLE = 'family_visitor_links';

    public function title(): string
    {
        return 'Links temporários';
    }

    public function description(): string
    {
        return 'Cria e revoga endereços temporários de acesso somente leitura.';
    }

    public function customModuleAuthorName(): string { return 'Lisandro Melo'; }
    public function customModuleVersion(): string { return '0.1.0'; }
    public function customModuleSupportUrl(): string { return 'https://github.com/lisandromelo/webtrees'; }
    public function resourcesFolder(): string { return __DIR__ . '/resources/'; }

    public function boot(): void
    {
        View::registerNamespace('family-visitor-links', $this->resourcesFolder() . 'views/');
    }

    public function getAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->layout = 'layouts/administration';

        $trees = $this->trees();
        $tree = $this->layoutTree($request, $trees);
        $trees_by_id = $trees->keyBy(static fn (Tree $candidate): int => $candidate->id());
        $links = [];

        foreach (DB::table(self::TABLE)->orderByDesc('id')->get() as $row) {
            $row_tree = $trees_by_id->get((int) $row->tree_id);
            $expired = $row->expires_at !== null && strtotime((string) $row->expires_at) < time();

            if ($row->revoked_at !== null) {
                $status = 'Revogado';
                $badge = 'text-bg-secondary';
            } elseif ($expired) {
                $status = 'Expirado';
                $badge = 'text-bg-warning';
            } else {
                $status = 'Ativo';
                $badge = 'text-bg-success';
            }

            $links[] = [
                'id'          => (int) $row->id,
                'tree_title'  => $row_tree?->title() ?? 'Árvore removida',
                'label'       => $row->label !== '' ? (string) $row->label : '—',
                'created_at'  => $this->formatWhen((string) $row->created_at),
                'expires_at'  => $row->expires_at === null ? 'Sem expiração' : $this->formatWhen((string) $row->expires_at),
                'status'      => $status,
                'badge'       => $badge,
                'can_revoke'  => $status === 'Ativo',
            ];
        }

        return $this->viewResponse('family-visitor-links::page', [
            'title'      => $this->title(),
            'tree'       => $tree,
            'trees'      => $trees,
            'links'      => $links,
            'create_url' => $this->actionUrl('Create', $tree),
            'revoke_url' => $this->actionUrl('Revoke', $tree),
        ]);
    }

    public function postCreateAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!Auth::isAdmin()) { throw new HttpForbiddenException(); }
        $tree_id = Validator::parsedBody($request)->integer('tree_id');
        $label = Validator::parsedBody($request)->string('label', '');
        $days = Validator::parsedBody($request)->integer('days', 0);
        $tree = $this->findTree($tree_id);
        if ($tree === null) { throw new HttpNotFoundException(); }
        $token = bin2hex(random_bytes(32));
        $expires_at = $days > 0 ? date('Y-m-d H:i:s', time() + min($days, 3650) * 86400) : null;
        DB::table(self::TABLE)->insert([
            'tree_id' => $tree->id(),
            'token_hash' => hash('sha256', $token),
            'created_at' => date('Y-m-d H:i:s'),
            'expires_at' => $expires_at,
            'label' => $label,
        ]);
        // Token is shown once so the administrator can copy it. Only the hash is stored.
        $url = route(ModuleAction::class, ['module' => $this->name(), 'action' => 'Visitor', 'token' => $token]);

        $this->layout = 'layouts/administration';

        return $this->viewResponse('family-visitor-links::created', [
            'title'       => 'Link criado',
            'links_title' => $this->title(),
            'tree'        => $tree,
            'url'         => $url,
            'admin_url'   => $this->adminUrl($tree),
            'label'       => $label,
            'expires_at'  => $expires_at === null ? 'Sem expiração' : $this->formatWhen($expires_at),
            'tree_title'  => $tree->title(),
        ]);
    }

    public function postRevokeAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!Auth::isAdmin(Validator::attributes($request)->user())) { throw new HttpForbiddenException(); }
        $id = Validator::parsedBody($request)->integer('id');
        DB::table(self::TABLE)->where('id','=',$id)->whereNull('revoked_at')->update(['revoked_at'=>date('Y-m-d H:i:s')]);
        $tree = Validator::attributes($request)->treeOptional();
        return redirect($this->adminUrl($tree));
    }

    public function getVisitorAction(ServerRequestInterface $request): ResponseInterface
    {
        $token = Validator::queryParams($request)->string('token');
        if ($token === '') {
            throw new HttpNotFoundException();
        }

        $link = DB::table(self::TABLE)
            ->where('token_hash', '=', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->first();

        if ($link === null || ($link->expires_at !== null && strtotime($link->expires_at) < time())) {
            throw new HttpForbiddenException('This visitor link is invalid, revoked, or expired.');
        }

        // Replace any signed-in account. The same browser must not keep administrator rights.
        $language = Session::get('language');
        $theme = Session::get('theme');
        Session::regenerate(true);
        Session::put('initiated', true);
        if (is_string($language) && $language !== '') {
            Session::put('language', $language);
        }
        if (is_string($theme) && $theme !== '') {
            Session::put('theme', $theme);
        }
        Session::put('family_visitor_link_id', (int) $link->id);
        Session::put('family_visitor_tree_id', (int) $link->tree_id);

        $tree = $this->findTree((int) $link->tree_id);
        if ($tree === null) {
            Session::forget('family_visitor_link_id');
            Session::forget('family_visitor_tree_id');
            throw new HttpNotFoundException();
        }

        return redirect(route(TreePage::class, ['tree' => $tree->name()]));
    }

    private function findTree(int $tree_id): Tree|null
    {
        if ($tree_id <= 0) {
            return null;
        }

        $row = DB::table('gedcom')->where('gedcom_id', '=', $tree_id)->first();

        return $row === null ? null : Tree::fromDB($row);
    }

    /**
     * @return Collection<int,Tree>
     */
    private function trees(): Collection
    {
        return DB::table('gedcom')
            ->where('gedcom_id', '>', 0)
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get()
            ->map(static fn (object $row): Tree => Tree::fromDB($row))
            ->values();
    }

    /**
     * @param Collection<int,Tree> $trees
     */
    private function layoutTree(ServerRequestInterface $request, Collection $trees): Tree|null
    {
        $attribute = $request->getAttribute('tree');

        if ($attribute instanceof Tree) {
            $match = $trees->first(static fn (Tree $tree): bool => $tree->id() === $attribute->id());

            if ($match instanceof Tree) {
                return $match;
            }
        }

        $first = $trees->first();

        return $first instanceof Tree ? $first : null;
    }

    private function adminUrl(Tree|null $tree): string
    {
        return $this->actionUrl('Admin', $tree);
    }

    private function actionUrl(string $action, Tree|null $tree): string
    {
        $parameters = [
            'module' => $this->name(),
            'action' => $action,
        ];

        if ($tree instanceof Tree) {
            $parameters['tree'] = $tree->name();
        }

        return route(ModuleAction::class, $parameters);
    }

    private function formatWhen(string $value): string
    {
        $format = trim(strtr(I18N::dateFormat(), ['%' => ''])) . ' H:i';

        return Registry::timestampFactory()->fromString($value)->translatedFormat($format);
    }
}

return new FamilyVisitorLinks();
