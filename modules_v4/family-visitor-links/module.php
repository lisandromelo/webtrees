<?php

declare(strict_types=1);

namespace Fisharebest\Webtrees\Module;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Http\Exceptions\HttpForbiddenException;
use Fisharebest\Webtrees\Http\Exceptions\HttpNotFoundException;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Session;
use Fisharebest\Webtrees\Http\RequestHandlers\ModuleAction;
use Fisharebest\Webtrees\Menu;
use Fisharebest\Webtrees\Http\Controllers\TreePage;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function e;
use function route;
use function response;
use function redirect;
use function random_bytes;
use function hash;
use function bin2hex;
use function time;

class FamilyVisitorLinks extends AbstractModule implements ModuleCustomInterface, ModuleMenuInterface
{
    use ModuleCustomTrait;
    use ModuleMenuTrait;

    private const TABLE = 'family_visitor_links';

    public function getMenu(Tree $tree): Menu|null
    {
        return new Menu(
            'Family visitor links',
            route(ModuleAction::class, ['module' => $this->name(), 'action' => 'Admin'])
        );
    }

    public function title(): string { return 'Family visitor links'; }
    public function description(): string { return 'Create and revoke temporary read-only family access links.'; }
    public function customModuleAuthorName(): string { return 'Lisandro Melo'; }
    public function customModuleVersion(): string { return '0.1.0'; }
    public function customModuleSupportUrl(): string { return 'https://github.com/lisandromelo/webtrees'; }
    public function resourcesFolder(): string { return __DIR__ . '/resources/'; }

    public function boot(): void
    {
        // Database DDL must not run during request boot: Webtrees wraps requests
        // in a transaction and MySQL CREATE TABLE causes an implicit commit.
    }

    public function getAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $rows = DB::table(self::TABLE)->orderByDesc('id')->get();
        $trees = Registry::tree()->all();
        $html = '<main class="container"><h1>Family visitor links</h1>';
        $html .= '<form method="post" action="' . e(route(ModuleAction::class, ['module'=>$this->name(),'action'=>'AdminCreate'])) . '">';
        $html .= '<label>Tree <select name="tree_id" required>';
        foreach ($trees as $tree) {
            $html .= '<option value="' . (int)$tree->id() . '">' . e($tree->title()) . '</option>';
        }
        $html .= '</select></label> <label>Label <input name="label" maxlength="120"></label>';
        $html .= '<label>Expiry (days; blank = no expiry) <input name="days" type="number" min="1" max="3650"></label>';
        $html .= '<button type="submit">Create link</button></form><hr><table class="table"><thead><tr><th>Tree</th><th>Label</th><th>Created</th><th>Expiry</th><th>Status</th><th>Action</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $tree = Tree::findById((int)$row->tree_id);
            $status = $row->revoked_at ? 'Revoked' : (($row->expires_at && strtotime($row->expires_at) < time()) ? 'Expired' : 'Active');
            $html .= '<tr><td>' . e($tree?->title() ?? 'Deleted tree') . '</td><td>' . e($row->label) . '</td><td>' . e($row->created_at) . '</td><td>' . e($row->expires_at ?? 'Never') . '</td><td>' . e($status) . '</td><td>';
            if ($status === 'Active') {
                $html .= '<form method="post" action="' . e(route(ModuleAction::class, ['module'=>$this->name(),'action'=>'AdminRevoke'])) . '"><input type="hidden" name="id" value="' . (int)$row->id . '"><button type="submit">Revoke</button></form>';
            }
            $html .= '</td></tr>';
        }
        $html .= '</tbody></table><p><strong>Note:</strong> Visitor-view integration is not yet enabled; links currently manage access tokens only.</p></main>';
        return response($html);
    }

    public function postAdminCreateAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree_id = Validator::parsedBody($request)->integer('tree_id');
        $label = Validator::parsedBody($request)->string('label', '');
        $days = Validator::parsedBody($request)->integer('days', 0);
        $tree = Tree::findById($tree_id);
        if ($tree === null) { throw new HttpNotFoundException(); }
        $token = bin2hex(random_bytes(32));
        DB::table(self::TABLE)->insert([
            'tree_id' => $tree_id,
            'token_hash' => hash('sha256', $token),
            'created_at' => date('Y-m-d H:i:s'),
            'expires_at' => $days > 0 ? date('Y-m-d H:i:s', time() + min($days,3650) * 86400) : null,
            'label' => $label,
        ]);
        // Token is shown once in the flash-free response so the administrator can copy it.
        $url = route(ModuleAction::class, ['module'=>$this->name(),'action'=>'Visitor','token'=>$token]);
        return response('<main class="container"><h1>Link created</h1><p>Copy this link now. The token is not stored in readable form.</p><input readonly style="width:100%" value="' . e($url) . '"><p><a href="' . e(route(ModuleAction::class,['module'=>$this->name(),'action'=>'Admin'])) . '">Back to management</a></p></main>');
    }

    public function postAdminRevokeAction(ServerRequestInterface $request): ResponseInterface
    {
        $id = Validator::parsedBody($request)->integer('id');
        DB::table(self::TABLE)->where('id','=',$id)->whereNull('revoked_at')->update(['revoked_at'=>date('Y-m-d H:i:s')]);
        return redirect(route(ModuleAction::class,['module'=>$this->name(),'action'=>'Admin']));
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

        Session::put('family_visitor_link_id', (int) $link->id);
        Session::put('family_visitor_tree_id', (int) $link->tree_id);

        $tree = Tree::findById((int) $link->tree_id);
        if ($tree === null) {
            Session::forget('family_visitor_link_id');
            Session::forget('family_visitor_tree_id');
            throw new HttpNotFoundException();
        }

        return redirect(route(TreePage::class, ['tree' => $tree->name()]));
    }
}

return new FamilyVisitorLinks();
