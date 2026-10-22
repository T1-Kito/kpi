<?php

namespace App\Models\Concerns;

/** Keep integer database identifiers consistent across SQLite and PDO_SQLSRV BIGINT. */
trait CastsDatabaseIdentifiers
{
    public function getCasts(): array
    {
        $casts=parent::getCasts();
        $keys=['tenant_id','user_id','customer_id','sales_owner_id','owner_id','created_by','updated_by',
            'requested_by','reviewed_by','accepted_by','confirmed_by','opened_by','received_by','issued_by',
            'assigned_to','assignee_id','approver_id','recipient_id','department_id','position_id','manager_id',
            'contact_id','contract_id','quotation_id','revision_root_id','duplicated_from_id','deal_id','lead_id',
            'product_id','sku_id','brand_id','category_id','warehouse_id','warehouse_location_id','receiving_warehouse_id',
            'purchase_order_id','purchase_request_id','supplier_id','supplier_quotation_id','goods_issue_id',
            'goods_receipt_id','sales_order_id','sales_invoice_id','stock_take_id','service_ticket_id','task_id',
            'kpi_bundle_id','kpi_definition_id','kpi_period_closure_id','kpi_score_snapshot_id','kpi_target_id',
            'audience_id','parent_id','merged_into_id','first_customer_id','second_customer_id','retained_customer_id',
            'payment_term_id','price_book_id','entity_id','source_id'];
        // Guarded models (for example KPI bundles) also hydrate these database columns.
        foreach($keys as $key) $casts[$key] ??= 'integer';
        return $casts;
    }
}
