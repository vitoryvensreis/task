<?php
declare(strict_types=1);

// Teste isolado do recorte por período, sem banco ou credenciais de produção.
// php tests/finance-period.php
final class Auth {
    public static array $user = ['id' => 1, 'role' => 'admin'];
    public static function admin(): void {}
}
final class WorkspaceContext {
    public static function legacyCiFixture(): bool { return true; }
}
function config(string $name, mixed $default = null): mixed {
    return $name === 'timezone' ? 'America/Fortaleza' : $default;
}
function datev(mixed $date, bool $nullable = true): ?string {
    return $date === null && $nullable ? null : (string) $date;
}
function fail(string $message, int $status = 400): never {
    throw new RuntimeException($message . ' (' . $status . ')');
}
final class DB {
    public static function all(string $sql, array $args = []): array {
        // Não existe cobrança de mensalidade/tarefa nem custo recorrente novo neste fixture.
        if (str_contains($sql, 'SELECT q.* FROM (')) {
            $invoices = [
                ['id' => 1, 'movement_on' => '2026-10-15', 'description' => 'Cobrança outubro',
                    'balance_cents' => 4000, 'amount_cents' => 4000, 'client_name' => 'Cliente A', 'source_type' => 'manual'],
                ['id' => 2, 'movement_on' => '2026-11-10', 'description' => 'Cobrança novembro',
                    'balance_cents' => 900000, 'amount_cents' => 900000, 'client_name' => 'Cliente B', 'source_type' => 'manual'],
            ];
            // A simulação só recorta pela data quando a query contém filtro e binds.
            if (!str_contains($sql, 'q.movement_on BETWEEN ? AND ?') || count($args) !== 2) {
                return $invoices; // O código antigo falhará no assert abaixo.
            }
            return array_values(array_filter(
                $invoices,
                fn(array $row): bool => $row['movement_on'] >= $args[0] && $row['movement_on'] <= $args[1]
            ));
        }
        if (str_contains($sql, 'FROM payments p') && str_contains($sql, 'ORDER BY p.paid_on DESC')) {
            // Pagamento realizado em outubro de uma cobrança que vence em novembro:
            // deve ser reconhecido pela data paga, não pela data do vencimento.
            return str_contains($sql, 'p.paid_on BETWEEN ? AND ?') &&
                !str_contains($sql, 'i.due_on BETWEEN ? AND ?') && $args === ['2026-10-01', '2026-10-31']
                ? [['id' => 11, 'invoice_id' => 20, 'amount_cents' => 2000, 'discount_cents' => 0,
                    'discount_type' => 'none', 'discount_percent' => null, 'movement_on' => '2026-10-17',
                    'method' => 'Pix', 'note' => '', 'refund_of' => null,
                    'description' => 'Pago antes do vencimento', 'client_name' => 'Cliente C']]
                : [];
        }
        if (str_contains($sql, 'FROM expenses e') && str_contains($sql, "payment_status='paid'")) {
            return str_contains($sql, 'e.paid_on BETWEEN ? AND ?')
                ? [['id' => 30, 'description' => 'Custo pago', 'vendor' => 'Fornecedor',
                    'category' => 'Operacional', 'amount_cents' => 500, 'movement_on' => '2026-10-18',
                    'source_type' => 'expense', 'task_title' => null, 'client_name' => null]]
                : [];
        }
        if (str_contains($sql, 'FROM expenses e') && str_contains($sql, "payment_status<>'paid'")) {
            return str_contains($sql, 'e.spent_on BETWEEN ? AND ?')
                ? [['id' => 31, 'description' => 'Custo pendente', 'vendor' => 'Fornecedor',
                    'category' => 'Operacional', 'amount_cents' => 300, 'movement_on' => '2026-10-27',
                    'source_type' => 'expense', 'task_title' => null, 'client_name' => null]]
                : [];
        }
        return [];
    }

    public static function one(string $sql, array $args = []): array {
        if (str_contains($sql, 'SUM(GREATEST(0,i.amount_cents') &&
            !str_contains($sql, 'i.due_on<?')) {
            // A análise deve contar os R$ 40 pendentes de outubro, não os R$ 9.040
            // abertos no conjunto dos meses.
            return ['n' => str_contains($sql, 'i.due_on BETWEEN ? AND ?') &&
                $args === ['2026-10-01', '2026-10-31'] ? 4000 : 904000];
        }
        return ['n' => 0];
    }
}

require dirname(__DIR__) . '/app/Finance.php';

function expectFinance(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FALHOU: {$message}\n");
        exit(1);
    }
}
$period = ['from' => '2026-10-01', 'to' => '2026-10-31'];
$breakdown = Finance::cashBreakdown($period);
$totals = $breakdown['totals'];
expectFinance($totals['received_cents'] === 2000, 'Pagamento de outra competência deve entrar pela data efetiva.');
expectFinance($totals['paid_out_cents'] === 500, 'Saída confirmada deve seguir a data paga.');
expectFinance($totals['future_in_cents'] === 4000, 'Vencimento fora do período não pode entrar no futuro.');
expectFinance(count($breakdown['future_in']) === 1, 'Lista de cobranças deve seguir o filtro.');
expectFinance($totals['future_out_cents'] === 300, 'Custo pendente deve seguir a data prevista.');
expectFinance($totals['current_cents'] === 1500, 'Saldo realizado deve usar os movimentos do intervalo.');
expectFinance($totals['final_cents'] === 5200, 'Projeção deve usar apenas movimentos do intervalo.');

$analysis = Finance::analysis($period);
expectFinance($analysis['open_cents'] === 4000, 'A receber na análise deve respeitar o período.');
expectFinance($analysis['future_in_cents'] === 4000, 'Projeção da análise deve acompanhar o detalhamento.');
echo "OK: caixa, pendências e projeção respeitam o período.\n";
