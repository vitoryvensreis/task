<?php
declare(strict_types=1);

/**
 * Read-only, least-privilege financial gateway for TaskEsc AI.
 * Mirrors the accounting definitions in Finance::analysis without invoking
 * syncCommercialInvoices/syncExpenses (GET must not create ledger entries).
 * A financial write must NEVER be routed through this gateway.
 */
final class TaskEscFinanceReadApi
{
    private static function authorize(): void {
        Auth::requireAbility('finance.read');
        Auth::admin();
    }

    private static function period(array $args): array {
        $tz=new DateTimeZone((string)config('timezone','America/Fortaleza'));
        $today=new DateTimeImmutable('now',$tz);
        $from=datev($args['from']??$today->modify('first day of this month')->format('Y-m-d'),false);
        $to=datev($args['to']??$today->modify('last day of this month')->format('Y-m-d'),false);
        if($from>$to)fail('Período financeiro inválido.',422);
        if((new DateTimeImmutable($from,$tz))->diff(new DateTimeImmutable($to,$tz))->days>366)fail('Consulte no máximo 12 meses por vez.',422);
        return [$from,$to,$today->format('Y-m-d')];
    }

    private static function total(string $sql,array $args=[]): int {
        return (int)(DB::one($sql,$args)['value_cents']??0);
    }

    /** Aggregate only; no payment notes or other clients' nonfinancial details. */
    public static function summary(array $args=[]): array {
        self::authorize();
        foreach(array_keys($args) as $key)if(!in_array($key,['from','to'],true))fail('Filtro financeiro não permitido.',422);
        [$from,$to,$today]=self::period($args);
        $billed=self::total("SELECT COALESCE(SUM(amount_cents),0) value_cents FROM invoices WHERE status<>'cancelled' AND deleted_at IS NULL AND due_on BETWEEN ? AND ?",[$from,$to]);
        $received=self::total("SELECT COALESCE(SUM(p.amount_cents),0) value_cents FROM payments p JOIN invoices i ON i.id=p.invoice_id WHERE i.status<>'cancelled' AND i.deleted_at IS NULL AND p.paid_on BETWEEN ? AND ?",[$from,$to]);
        $open=self::total("SELECT COALESCE(SUM(GREATEST(0,i.amount_cents-(SELECT COALESCE(SUM(p.amount_cents+COALESCE(p.discount_cents,0)),0) FROM payments p WHERE p.invoice_id=i.id))),0) value_cents FROM invoices i WHERE i.status<>'cancelled' AND i.deleted_at IS NULL");
        $overdue=self::total("SELECT COALESCE(SUM(GREATEST(0,i.amount_cents-(SELECT COALESCE(SUM(p.amount_cents+COALESCE(p.discount_cents,0)),0) FROM payments p WHERE p.invoice_id=i.id))),0) value_cents FROM invoices i WHERE i.status<>'cancelled' AND i.deleted_at IS NULL AND i.due_on<?",[$today]);
        $expense=self::total("SELECT COALESCE(SUM(amount_cents),0) value_cents FROM expenses WHERE deleted_at IS NULL AND spent_on BETWEEN ? AND ?",[$from,$to])
            +self::total("SELECT COALESCE(SUM(amount_cents),0) value_cents FROM costs WHERE spent_on BETWEEN ? AND ?",[$from,$to]);
        $paidCosts=self::total("SELECT COALESCE(SUM(amount_cents),0) value_cents FROM expenses WHERE deleted_at IS NULL AND payment_status='paid' AND paid_on BETWEEN ? AND ?",[$from,$to])
            +self::total("SELECT COALESCE(SUM(amount_cents),0) value_cents FROM costs WHERE payment_status='paid' AND paid_on BETWEEN ? AND ?",[$from,$to]);
        return ['kind'=>'finance_summary','from'=>$from,'to'=>$to,'billed_cents'=>$billed,'received_cents'=>$received,
            'open_all_cents'=>$open,'overdue_all_cents'=>$overdue,'expense_cents'=>$expense,
            'paid_expense_cents'=>$paidCosts,'profit_cents'=>$received-$paidCosts,'registered_only'=>true];
    }

    public static function receivables(array $args=[]): array {
        self::authorize();
        foreach(array_keys($args) as $key)if(!in_array($key,['scope','from','to','client_id','limit'],true))fail('Filtro financeiro não permitido.',422);
        $scope=enumv($args['scope']??'month',['month','all'],'período');
        if($scope==='all'&&(array_key_exists('from',$args)||array_key_exists('to',$args)))fail('Use período mensal ou todas as cobranças, não ambos.',422);
        [$from,$to,$today]=self::period($args);
        $where="i.status<>'cancelled' AND i.deleted_at IS NULL";$params=[];
        if($scope==='month'){$where.=" AND i.due_on BETWEEN ? AND ?";$params=[$from,$to];}
        if(array_key_exists('client_id',$args)){
            $clientId=integer($args['client_id'],1);
            Auth::client($clientId);
            $where.=" AND i.client_id=?";$params[]=$clientId;
        }
        $limit=integer($args['limit']??10,1,10);
        $remaining="GREATEST(0,i.amount_cents-(SELECT COALESCE(SUM(p.amount_cents+COALESCE(p.discount_cents,0)),0) FROM payments p WHERE p.invoice_id=i.id))";
        $stats=DB::one("SELECT COUNT(*) count_invoices,COALESCE(SUM($remaining),0) total_cents,COALESCE(SUM(CASE WHEN i.due_on<? THEN $remaining ELSE 0 END),0) overdue_cents FROM invoices i WHERE $where AND $remaining>0",[$today,...$params]);
        $rows=DB::all("SELECT i.id,c.name AS client_name,i.description,i.due_on,i.amount_cents,$remaining AS balance_cents FROM invoices i JOIN clients c ON c.id=i.client_id WHERE $where AND $remaining>0 ORDER BY i.due_on,i.id LIMIT $limit",$params);
        foreach($rows as &$r){
            $r=['id'=>(int)$r['id'],'client'=>(string)$r['client_name'],'description'=>mb_substr((string)$r['description'],0,90),
                'due_on'=>(string)$r['due_on'],'balance_cents'=>(int)$r['balance_cents'],'overdue'=>$r['due_on']<$today];
        }unset($r);
        return ['kind'=>'finance_receivables','scope'=>$scope,'from'=>$scope==='month'?$from:null,'to'=>$scope==='month'?$to:null,
            'total_cents'=>(int)($stats['total_cents']??0),'overdue_cents'=>(int)($stats['overdue_cents']??0),
            'count'=>(int)($stats['count_invoices']??0),'rows'=>$rows,'registered_only'=>true];
    }

    public static function expenses(array $args=[]): array {
        self::authorize();
        foreach(array_keys($args) as $key)
            if(!in_array($key,['from','to','limit','payment_status'],true))fail('Filtro financeiro não permitido.',422);
        [$from,$to]=self::period($args);
        $limit=integer($args['limit']??10,1,10);
        $filter=enumv($args['payment_status']??'all',['all','pending','paid'],'situação de pagamento');
        // Exactly the same scope for both FULL totals and limited visible rows.
        // Totals must never be computed from the first 10 displayed items.
        $base="FROM (
            SELECT e.spent_on entry_date,e.description,e.category,e.amount_cents,e.payment_status
              FROM expenses e WHERE e.deleted_at IS NULL AND e.spent_on BETWEEN ? AND ?
            UNION ALL
            SELECT c.spent_on entry_date,c.description,'Custos de task' category,c.amount_cents,c.payment_status
              FROM costs c WHERE c.spent_on BETWEEN ? AND ?
          ) x";
        $params=[$from,$to,$from,$to];
        $stats=DB::one("SELECT
            COUNT(*) count_all,
            COALESCE(SUM(amount_cents),0) total_all_cents,
            COALESCE(SUM(CASE WHEN payment_status='pending' THEN amount_cents ELSE 0 END),0) pending_total_cents,
            COALESCE(SUM(CASE WHEN payment_status='paid' THEN amount_cents ELSE 0 END),0) paid_total_cents,
            COALESCE(SUM(CASE WHEN payment_status='pending' THEN 1 ELSE 0 END),0) pending_count,
            COALESCE(SUM(CASE WHEN payment_status='paid' THEN 1 ELSE 0 END),0) paid_count
            $base",$params);
        $where=$filter==='all'?'':' WHERE payment_status=?';
        $rowParams=$filter==='all'?$params:[...$params,$filter];
        $rows=DB::all("SELECT entry_date,description,category,amount_cents,payment_status $base $where
            ORDER BY entry_date DESC,description LIMIT $limit",$rowParams);
        foreach($rows as &$r)$r=[
            'date'=>(string)$r['entry_date'],
            'description'=>mb_substr((string)$r['description'],0,90),
            'category'=>(string)$r['category'],
            'amount_cents'=>(int)$r['amount_cents'],
            'paid'=>(string)$r['payment_status']==='paid'
        ];
        unset($r);
        $count=$filter==='pending'?(int)$stats['pending_count']
            :($filter==='paid'?(int)$stats['paid_count']:(int)$stats['count_all']);
        $total=$filter==='pending'?(int)$stats['pending_total_cents']
            :($filter==='paid'?(int)$stats['paid_total_cents']:(int)$stats['total_all_cents']);
        return [
            'kind'=>'finance_expenses','from'=>$from,'to'=>$to,
            'payment_status'=>$filter,'count'=>$count,'total_cents'=>$total,
            'pending_count'=>(int)$stats['pending_count'],
            'paid_count'=>(int)$stats['paid_count'],
            'pending_total_cents'=>(int)$stats['pending_total_cents'],
            'paid_total_cents'=>(int)$stats['paid_total_cents'],
            'rows'=>$rows,'registered_only'=>true,
        ];
    }
}
