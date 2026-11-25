<?php

declare(strict_types=1);

namespace Fisharebest\Webtrees\Module;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Http\Exceptions\HttpForbiddenException;
use Fisharebest\Webtrees\Http\Exceptions\HttpNotFoundException;
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
        $trees = DB::table('gedcom')->get()->map(static fn (object $row): Tree => Tree::fromDB($row));
        $trees_by_id = $trees->keyBy(static fn (Tree $tree): int => $tree->id());
        $html = '<style>
            .fvl-wrap{max-width:1100px;margin:30px auto;padding:0 20px;font-family:system-ui,-apple-system,Segoe UI,sans-serif;color:#263238}
            .fvl-head{margin-bottom:24px}.fvl-head h1{font-size:2rem;margin:0 0 8px;font-weight:650}.fvl-head p{color:#667085;margin:0}
            .fvl-card{background:#fff;border:1px solid #e1e5e9;border-radius:12px;padding:22px;margin:18px 0;box-shadow:0 2px 8px rgba(20,30,40,.04)}
            .fvl-card h2{font-size:1.25rem;margin:0 0 16px}.fvl-form{display:flex;flex-wrap:wrap;align-items:end;gap:16px}
            .fvl-field{display:flex;flex-direction:column;gap:6px;flex:1;min-width:180px}.fvl-field label{font-weight:600;font-size:.9rem}
            .fvl-field input,.fvl-field select{border:1px solid #cbd5e1;border-radius:7px;padding:10px 12px;font-size:1rem;background:#fff}
            .fvl-btn{border:0;border-radius:7px;padding:10px 16px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-block}
            .fvl-primary{background:#2563eb;color:#fff}.fvl-primary:hover{background:#1d4ed8}.fvl-danger{background:#fee2e2;color:#991b1b}
            .fvl-table-wrap{overflow-x:auto}.fvl-table{width:100%;border-collapse:collapse;font-size:.95rem}.fvl-table th,.fvl-table td{padding:12px 14px;border-bottom:1px solid #e5e7eb;text-align:left}
            .fvl-table th{background:#f8fafc;color:#475569;font-weight:650}.fvl-table tr:hover td{background:#fafcff}
            .fvl-badge{display:inline-block;border-radius:999px;padding:4px 10px;font-size:.82rem;font-weight:650}
            .fvl-active{background:#dcfce7;color:#166534}.fvl-expired{background:#fef3c7;color:#92400e}.fvl-revoked{background:#f1f5f9;color:#475569}
            .fvl-note{background:#eff6ff;border-left:4px solid #3b82f6;border-radius:6px;padding:12px 15px;color:#1e40af;margin-top:18px}
            .fvl-empty{padding:26px;text-align:center;color:#64748b}
            @media(max-width:650px){.fvl-wrap{padding:0 12px;margin:16px auto}.fvl-card{padding:15px}.fvl-form{display:block}.fvl-field{margin-bottom:12px}.fvl-form button{width:100%}}
            </style><main class="fvl-wrap"><header class="fvl-head"><h1>Family visitor links</h1><p>Gerencie links temporários de acesso para visitantes da sua árvore genealógica.</p></header>';
        $html .= '<section class="fvl-card"><h2>Criar novo link</h2><form class="fvl-form" method="post" action="' . e(route(ModuleAction::class, ['module'=>$this->name(),'action'=>'AdminCreate'])) . '">';
        $html .= '<div class="fvl-field"><label for="fvl-tree">Árvore genealógica</label><select id="fvl-tree" name="tree_id" required>';
        foreach ($trees as $tree) {
            $html .= '<option value="' . (int)$tree->id() . '">' . e($tree->title()) . '</option>';
        }
        $html .= '</select></div><div class="fvl-field"><label for="fvl-label">Identificação (opcional)</label><input id="fvl-label" name="label" maxlength="120" placeholder="Ex.: Família — encontro 2026"></div>';
        $html .= '<div class="fvl-field"><label for="fvl-days">Validade em dias</label><input id="fvl-days" name="days" type="number" min="1" max="3650" placeholder="Em branco: sem expiração"></div><button class="fvl-btn fvl-primary" type="submit">Criar link</button></form></section>';
        $html .= '<section class="fvl-card"><h2>Links cadastrados</h2><div class="fvl-table-wrap"><table class="fvl-table"><thead><tr><th>Árvore</th><th>Identificação</th><th>Criado em</th><th>Validade</th><th>Status</th><th>Ação</th></tr></thead><tbody>';
        if ($rows->isEmpty()) {
            $html .= '<tr><td colspan="6" class="fvl-empty">Nenhum link criado ainda.</td></tr>';
        }
        foreach ($rows as $row) {
            $tree = $trees_by_id->get((int) $row->tree_id);
            $status = $row->revoked_at ? 'Revogado' : (($row->expires_at && strtotime($row->expires_at) < time()) ? 'Expirado' : 'Ativo');
            $badge = $status === 'Ativo' ? 'fvl-active' : ($status === 'Expirado' ? 'fvl-expired' : 'fvl-revoked');
            $html .= '<tr><td>' . e($tree?->title() ?? 'Árvore removida') . '</td><td>' . e($row->label ?: '—') . '</td><td>' . e($row->created_at) . '</td><td>' . e($row->expires_at ?? 'Sem expiração') . '</td><td><span class="fvl-badge ' . $badge . '">' . e($status) . '</span></td><td>';
            if ($status === 'Ativo') {
                $html .= '<form method="post" action="' . e(route(ModuleAction::class, ['module'=>$this->name(),'action'=>'AdminRevoke'])) . '"><input type="hidden" name="id" value="' . (int)$row->id . '"><button class="fvl-btn fvl-danger" type="submit">Revogar</button></form>';
            } else {
                $html .= '—';
            }
            $html .= '</td></tr>';
        }
        $html .= '</tbody></table></div><div class="fvl-note"><strong>Importante:</strong> nesta etapa, o módulo gerencia a criação e revogação dos tokens. A visualização específica para visitantes ainda será implementada.</div></section></main>';
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
