<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AlertController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\CustomerDuplicateReviewController;
use App\Http\Controllers\Api\V1\ContractController;
use App\Http\Controllers\Api\V1\DashboardSummaryController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\DealController;
use App\Http\Controllers\Api\V1\InventoryBalanceController;
use App\Http\Controllers\Api\V1\InventoryTransactionController;
use App\Http\Controllers\Api\V1\GoodsIssueController;
use App\Http\Controllers\Api\V1\GoodsReceiptController;
use App\Http\Controllers\Api\V1\KpiController;
use App\Http\Controllers\Api\V1\LeadController;
use App\Http\Controllers\Api\V1\LookupController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\PositionController;
use App\Http\Controllers\Api\V1\ProductCatalogController;
use App\Http\Controllers\Api\V1\PrintTemplateController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\PurchaseRequestController;
use App\Http\Controllers\Api\V1\QuotationController;
use App\Http\Controllers\Api\V1\RoleAdminController;
use App\Http\Controllers\Api\V1\SalesOrderController;
use App\Http\Controllers\Api\V1\SalesFulfillmentController;
use App\Http\Controllers\Api\V1\SalesMasterDataController;
use App\Http\Controllers\Api\V1\SkuController;
use App\Http\Controllers\Api\V1\SlaPolicyController;
use App\Http\Controllers\Api\V1\ServiceDeskController;
use App\Http\Controllers\Api\V1\StockTakeController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\SupplierQuotationController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\TenantSettingController;
use App\Http\Controllers\Api\V1\UserAdminController;
use App\Http\Controllers\Api\V1\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);

    Route::middleware('jwt')->group(function () {
        Route::get('/me', [MeController::class, 'show']);
        Route::get('/dashboard/summary', DashboardSummaryController::class);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/lookups/users', [LookupController::class, 'users']);
        Route::get('/lookups/sales-users', [LookupController::class, 'salesUsers']);
        Route::get('/lookups/departments', [LookupController::class, 'departments']);

        Route::middleware('permission:master.view')->group(function () {
            Route::get('/customers', [CustomerController::class, 'index']);
            Route::get('/customer-contacts', [CustomerController::class, 'contacts']);
            Route::get('/customers/duplicates', [CustomerController::class, 'duplicates']);
            Route::get('/customers/{customer}', [CustomerController::class, 'show']);
            Route::get('/suppliers', [SupplierController::class, 'index']);
            Route::get('/suppliers/{supplier}', [SupplierController::class, 'show']);
            Route::get('/skus', [SkuController::class, 'index']);
            Route::get('/skus/{sku}', [SkuController::class, 'show']);
            Route::get('/product-categories', [ProductCatalogController::class, 'categories']);
            Route::get('/product-brands', [ProductCatalogController::class, 'brands']);
            Route::get('/warehouses', [WarehouseController::class, 'index']);
            Route::get('/warehouses/{warehouse}', [WarehouseController::class, 'show']);
            Route::get('/inventory-balances', [InventoryBalanceController::class, 'index']);
            Route::get('/inventory-transactions', [InventoryTransactionController::class, 'index']);
            Route::get('/sales-master-data', [SalesMasterDataController::class, 'index']);
        });

        Route::middleware('permission:master.manage')->group(function () {
            Route::post('/customers', [CustomerController::class, 'store']);
            Route::post('/customer-contacts', [CustomerController::class, 'storeDirectoryContact']);
            Route::put('/customers/{customer}', [CustomerController::class, 'update']);
            Route::post('/customers/{customer}/contacts', [CustomerController::class, 'storeContact']);
            Route::put('/customers/{customer}/contacts/{contact}', [CustomerController::class, 'updateContact']);
            Route::delete('/customers/{customer}/contacts/{contact}', [CustomerController::class, 'deleteContact']);
            Route::post('/suppliers', [SupplierController::class, 'store']);
            Route::put('/suppliers/{supplier}', [SupplierController::class, 'update']);
            Route::post('/skus', [SkuController::class, 'store']);
            Route::put('/skus/{sku}', [SkuController::class, 'update']);
            Route::post('/product-categories', [ProductCatalogController::class, 'storeCategory']);
            Route::post('/product-brands', [ProductCatalogController::class, 'storeBrand']);
            Route::post('/warehouses', [WarehouseController::class, 'store']);
            Route::put('/warehouses/{warehouse}', [WarehouseController::class, 'update']);
            Route::post('/sales-master-data/stages', [SalesMasterDataController::class, 'storeStage']);
            Route::put('/sales-master-data/stages/{stage}', [SalesMasterDataController::class, 'updateStage']);
            Route::post('/sales-master-data/sources', [SalesMasterDataController::class, 'storeSource']);
            Route::put('/sales-master-data/sources/{source}', [SalesMasterDataController::class, 'updateSource']);
            Route::post('/sales-master-data/price-books', [SalesMasterDataController::class, 'storePriceBook']);
            Route::put('/sales-master-data/price-books/{priceBook}', [SalesMasterDataController::class, 'updatePriceBook']);
            Route::post('/sales-master-data/payment-terms', [SalesMasterDataController::class, 'storePaymentTerm']);
            Route::put('/sales-master-data/payment-terms/{paymentTerm}', [SalesMasterDataController::class, 'updatePaymentTerm']);
        });

        Route::middleware('permission:master.merge')->group(function () {
            Route::get('/customer-duplicate-reviews', [CustomerDuplicateReviewController::class, 'index']);
            Route::post('/customer-duplicate-reviews/scan', [CustomerDuplicateReviewController::class, 'scan']);
            Route::post('/customer-duplicate-reviews/{review}/dismiss', [CustomerDuplicateReviewController::class, 'dismiss']);
            Route::post('/customer-duplicate-reviews/{review}/merge', [CustomerDuplicateReviewController::class, 'merge']);
        });

        Route::middleware('permission:task.view')->group(function () {
            Route::get('/tasks', [TaskController::class, 'index']);
            Route::get('/tasks/{task}', [TaskController::class, 'show']);
        });

        Route::middleware('permission:task.create')->group(function () {
            Route::post('/tasks', [TaskController::class, 'store']);
            Route::post('/tasks/{task}/status', [TaskController::class, 'updateStatus']);
        });

        Route::middleware('permission:alert.view')->group(function () {
            Route::get('/alerts', [AlertController::class, 'index']);
            Route::post('/alerts/{alert}/status', [AlertController::class, 'updateStatus']);
        });

        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
        Route::get('/print-templates/active', [PrintTemplateController::class, 'active']);
        Route::get('/print-templates/choices', [PrintTemplateController::class, 'choices']);

        Route::middleware('permission:audit.view')->group(function () {
            Route::get('/audit-logs', [AuditLogController::class, 'index']);
            Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show']);
        });

        Route::get('/kpi/overview', [KpiController::class, 'overview']);
        Route::get('/kpi/snapshots', [KpiController::class, 'snapshots']);
        Route::get('/kpi/definitions', [KpiController::class, 'definitions']);
        Route::get('/kpi/targets', [KpiController::class, 'targets']);
        Route::get('/kpi/exceptions', [KpiController::class, 'exceptions']);
        Route::get('/kpi/adjustments', [KpiController::class, 'adjustments']);
        Route::get('/kpi/adjustments/summary', [KpiController::class, 'adjustmentSummary']);
        Route::post('/kpi/exceptions', [KpiController::class, 'storeException']);

        Route::middleware('permission:kpi.lock')->group(function () {
            Route::post('/kpi/snapshots/calculate', [KpiController::class, 'calculate']);
            Route::post('/kpi/definitions', [KpiController::class, 'storeDefinition']);
            Route::put('/kpi/definitions/{kpiDefinition}', [KpiController::class, 'updateDefinition']);
            Route::post('/kpi/targets', [KpiController::class, 'storeTarget']);
            Route::put('/kpi/targets/{kpiTarget}', [KpiController::class, 'updateTarget']);
            Route::post('/kpi/targets/{kpiTarget}/lock', [KpiController::class, 'lockTarget']);
            Route::post('/kpi/exceptions/{kpiException}/review', [KpiController::class, 'reviewException']);
            Route::post('/kpi/adjustments', [KpiController::class, 'storeAdjustment']);
            Route::post('/kpi/adjustments/{kpiAdjustment}/review', [KpiController::class, 'reviewAdjustment']);
        });

        Route::middleware('permission:user.manage')->group(function () {
            Route::get('/tenant/work-assignment', [TenantSettingController::class, 'workAssignment']);
            Route::post('/tenant/work-assignment', [TenantSettingController::class, 'saveWorkAssignment']);
            Route::post('/tenant/logo', [TenantSettingController::class, 'updateLogo']);
            Route::post('/tenant/ui-settings', [TenantSettingController::class, 'updateUiSettings']);
            Route::get('/tenant/procurement-approval', [TenantSettingController::class, 'procurementApproval']);
            Route::post('/tenant/procurement-approval', [TenantSettingController::class, 'saveProcurementApproval']);
            Route::get('/tenant/operational-approval', [TenantSettingController::class, 'operationalApproval']);
            Route::post('/tenant/operational-approval', [TenantSettingController::class, 'saveOperationalApproval']);
            Route::get('/tenant/sales-quotation-approval', [TenantSettingController::class, 'salesQuotationApproval']);
            Route::post('/tenant/sales-quotation-approval', [TenantSettingController::class, 'saveSalesQuotationApproval']);
            Route::post('/tenant/sidebar-icons', [TenantSettingController::class, 'uploadSidebarIcon']);
            Route::get('/sla-policies', [SlaPolicyController::class, 'index']);
            Route::post('/sla-policies', [SlaPolicyController::class, 'store']);
            Route::put('/sla-policies/{slaPolicy}', [SlaPolicyController::class, 'update']);
            Route::get('/users', [UserAdminController::class, 'index']);
            Route::post('/users', [UserAdminController::class, 'store']);
            Route::post('/users/{user}/roles', [UserAdminController::class, 'syncRoles']);
            Route::post('/users/{user}/organization', [UserAdminController::class, 'updateOrganization']);
            Route::post('/users/{user}/status', [UserAdminController::class, 'updateStatus']);
            Route::get('/departments', [DepartmentController::class, 'index']);
            Route::post('/departments', [DepartmentController::class, 'store']);
            Route::put('/departments/{department}', [DepartmentController::class, 'update']);
            Route::get('/positions', [PositionController::class, 'index']);
            Route::post('/positions', [PositionController::class, 'store']);
            Route::put('/positions/{position}', [PositionController::class, 'update']);
            Route::get('/print-templates', [PrintTemplateController::class, 'index']);
            Route::post('/print-templates', [PrintTemplateController::class, 'store']);
            Route::post('/print-templates/{printTemplate}', [PrintTemplateController::class, 'update']);
            Route::put('/print-templates/{printTemplate}', [PrintTemplateController::class, 'update']);
        });

        Route::middleware('permission:role.manage')->group(function () {
            Route::get('/roles', [RoleAdminController::class, 'index']);
            Route::post('/roles', [RoleAdminController::class, 'store']);
            Route::post('/roles/{role}/permissions', [RoleAdminController::class, 'syncPermissions']);
            Route::get('/permissions', [PermissionController::class, 'index']);
        });

        Route::middleware('permission:sales.lead.manage')->group(function () {
            Route::get('/leads', [LeadController::class, 'index']);
            Route::get('/leads/{lead}', [LeadController::class, 'show']);
            Route::post('/leads', [LeadController::class, 'store']);
            Route::post('/leads/{lead}/assign', [LeadController::class, 'assign']);
            Route::post('/leads/{lead}/follow-ups', [LeadController::class, 'createFollowUp']);
            Route::post('/leads/{lead}/follow-ups/{task}/status', [LeadController::class, 'updateFollowUpStatus']);
            Route::post('/leads/{lead}/qualify', [LeadController::class, 'qualify']);
        });

        Route::middleware('permission:sales.deal.manage')->group(function () {
            Route::get('/deals', [DealController::class, 'index']);
            Route::post('/deals', [DealController::class, 'store']);
    Route::get('/deals/{deal}', [DealController::class, 'show']);
    Route::post('/deals/{deal}/transition', [DealController::class, 'transition']);
    Route::put('/deals/{deal}/items', [DealController::class, 'syncItems']);
    Route::post('/deals/{deal}/activities', [DealController::class, 'addActivity']);
    Route::post('/deals/{deal}/activities/{task}/complete', [DealController::class, 'completeActivity']);
        });

        Route::middleware('permission:sales.contract.manage')->group(function () {
            Route::get('/contracts', [ContractController::class, 'index']);
            Route::get('/contracts/{contract}', [ContractController::class, 'show']);
            Route::post('/contracts', [ContractController::class, 'store']);
            Route::get('/contracts/{contract}/word', [ContractController::class, 'exportWord']);
            Route::post('/contracts/{contract}/activate', [ContractController::class, 'activate']);
            Route::post('/contracts/{contract}/milestones/{milestone}/accept', [ContractController::class, 'acceptMilestone']);
        });

        Route::middleware('permission:sales.quotation.view|sales.margin.approve')->group(function () {
            Route::get('/quotations', [QuotationController::class, 'index']);
            Route::get('/quotations/{quotation}', [QuotationController::class, 'show']);
            Route::get('/quotations/{quotation}/word', [QuotationController::class, 'exportWord']);
            Route::get('/quotations/{quotation}/issued-file', [QuotationController::class, 'issuedFile']);
        });

        Route::middleware('permission:sales.quotation.create')->group(function () {
            Route::post('/quotations', [QuotationController::class, 'store']);
            Route::post('/quotations/{quotation}/duplicate', [QuotationController::class, 'duplicate']);
            Route::post('/customers/quick', [CustomerController::class, 'quickStore']);
            Route::get('/customers/tax-lookup/{taxCode}', [CustomerController::class, 'lookupTaxCode']);
        });

        Route::middleware('permission:sales.quotation.edit')->group(function () {
            Route::put('/quotations/{quotation}', [QuotationController::class, 'update']);
            Route::post('/quotations/{quotation}/workflow/{action}', [QuotationController::class, 'workflow'])->where('action', 'submit|revise|issue|customer-response');
        });

        Route::middleware('permission:sales.quotation.delete')->group(function () {
            Route::delete('/quotations/{quotation}', [QuotationController::class, 'destroy']);
        });

        Route::middleware('permission:sales.margin.approve')->group(function () {
            Route::post('/quotations/{quotation}/approve', [QuotationController::class, 'approve']);
        });

        Route::middleware('permission:sales.order.view')->group(function () {
            Route::get('/sales-orders', [SalesOrderController::class, 'index']);
            Route::get('/sales-orders/{salesOrder}', [SalesOrderController::class, 'show']);
            Route::get('/sales-orders/{salesOrder}/word', [SalesOrderController::class, 'exportWord']);
        });

        Route::middleware('permission:sales.order.create')->group(function () {
            Route::post('/sales-orders', [SalesOrderController::class, 'store']);
            Route::post('/sales-orders/{salesOrder}/confirm', [SalesOrderController::class, 'confirm']);
        });

        Route::middleware('permission:sales.order.edit')->group(function () {
            Route::put('/sales-orders/{salesOrder}', [SalesOrderController::class, 'update']);
        });

        Route::middleware('permission:sales.order.delete')->group(function () {
            Route::delete('/sales-orders/{salesOrder}', [SalesOrderController::class, 'destroy']);
        });

        Route::middleware('permission:sales.delivery.view')->group(function () {
            Route::get('/deliveries', [SalesFulfillmentController::class, 'deliveries']);
            Route::get('/deliveries/{salesOrder}', [SalesFulfillmentController::class, 'delivery']);
        });

        Route::middleware('permission:sales.delivery.confirm')->group(function () {
            Route::post('/sales-orders/{salesOrder}/deliveries', [SalesFulfillmentController::class, 'confirmDelivery']);
        });

        Route::middleware('permission:finance.invoice.view')->group(function () {
            Route::get('/sales-invoices', [SalesFulfillmentController::class, 'invoices']);
            Route::get('/sales-invoices/{salesInvoice}', [SalesFulfillmentController::class, 'invoice']);
        });

        Route::middleware('permission:finance.invoice.manage')->group(function () {
            Route::post('/sales-orders/{salesOrder}/invoices', [SalesFulfillmentController::class, 'issueInvoice']);
        });

        Route::middleware('permission:finance.payment.view')->group(function () {
            Route::get('/customer-receivables', [SalesFulfillmentController::class, 'receivables']);
            Route::get('/customer-payments', [SalesFulfillmentController::class, 'payments']);
            Route::get('/customer-payments/{customerPayment}', [SalesFulfillmentController::class, 'payment']);
        });

        Route::middleware('permission:finance.payment.record')->group(function () {
            Route::post('/sales-invoices/{salesInvoice}/payments', [SalesFulfillmentController::class, 'recordPayment']);
        });

        Route::middleware('permission:procurement.pr.approve')->group(function () {
            Route::get('/purchase-requests', [PurchaseRequestController::class, 'index']);
            Route::post('/purchase-requests', [PurchaseRequestController::class, 'store']);
            Route::get('/purchase-requests/{purchaseRequest}', [PurchaseRequestController::class, 'show']);
            Route::post('/purchase-requests/{purchaseRequest}/approve', [PurchaseRequestController::class, 'approve']);
            Route::post('/purchase-requests/{purchaseRequest}/reject', [PurchaseRequestController::class, 'reject']);
        });

        Route::middleware('permission:procurement.po.approve')->group(function () {
            Route::get('/supplier-quotations', [SupplierQuotationController::class, 'index']);
            Route::post('/supplier-quotations', [SupplierQuotationController::class, 'store']);
            Route::get('/supplier-quotations/{supplierQuotation}', [SupplierQuotationController::class, 'show']);
            Route::post('/supplier-quotations/{supplierQuotation}/document', [SupplierQuotationController::class, 'uploadDocument']);
            Route::get('/supplier-quotations/{supplierQuotation}/document', [SupplierQuotationController::class, 'document']);
            Route::post('/supplier-quotations/{supplierQuotation}/select', [SupplierQuotationController::class, 'select']);
            Route::post('/supplier-quotations/{supplierQuotation}/approval-flow', [SupplierQuotationController::class, 'configureApprovalFlow']);
            Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
            Route::post('/purchase-orders', [PurchaseOrderController::class, 'store']);
            Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show']);
            Route::post('/purchase-orders/{purchaseOrder}/approval-flow', [PurchaseOrderController::class, 'configureApprovalFlow']);
            Route::post('/purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve']);
            Route::post('/purchase-orders/{purchaseOrder}/reject', [PurchaseOrderController::class, 'reject']);
        });

        Route::middleware('permission:inventory.receipt.confirm')->group(function () {
            Route::get('/goods-receipts', [GoodsReceiptController::class, 'index']);
            Route::post('/goods-receipts', [GoodsReceiptController::class, 'store']);
            Route::get('/goods-receipts/{goodsReceipt}', [GoodsReceiptController::class, 'show']);
            Route::post('/goods-receipts/{goodsReceipt}/confirm', [GoodsReceiptController::class, 'confirm']);
        });

        Route::middleware('permission:inventory.issue.confirm')->group(function () {
            Route::get('/goods-issues', [GoodsIssueController::class, 'index']);
            Route::post('/goods-issues', [GoodsIssueController::class, 'store']);
            Route::get('/goods-issues/{goodsIssue}', [GoodsIssueController::class, 'show']);
            Route::get('/goods-issues/{goodsIssue}/word', [GoodsIssueController::class, 'exportWord']);
            Route::post('/goods-issues/{goodsIssue}/confirm', [GoodsIssueController::class, 'confirm']);
            Route::get('/stock-takes', [StockTakeController::class, 'index']);
            Route::post('/stock-takes', [StockTakeController::class, 'store']);
            Route::get('/stock-takes/{stockTake}', [StockTakeController::class, 'show']);
            Route::post('/stock-takes/{stockTake}/confirm', [StockTakeController::class, 'confirm']);
        });

        Route::middleware('permission:service.ticket.view')->group(function () {
            Route::get('/service-tickets', [ServiceDeskController::class, 'tickets']);
            Route::get('/service-tickets/{ticket}', [ServiceDeskController::class, 'showTicket']);
            Route::get('/warranty-claims', [ServiceDeskController::class, 'warranties']);
            Route::get('/warranty-customers/lookup/{taxCode}', [ServiceDeskController::class, 'lookupWarrantyCustomer']);
        });

        Route::middleware('permission:service.ticket.manage')->group(function () {
            Route::post('/service-tickets', [ServiceDeskController::class, 'storeTicket']);
            Route::post('/service-tickets/{ticket}/worklogs', [ServiceDeskController::class, 'addWorklog']);
            Route::put('/service-tickets/{ticket}', [ServiceDeskController::class, 'updateTicket']);
            Route::post('/warranty-claims', [ServiceDeskController::class, 'storeWarranty']);
        });
    });
});
