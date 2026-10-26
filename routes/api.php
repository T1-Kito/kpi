<?php

use App\Http\Controllers\Api\V1\AlertController;
use App\Http\Controllers\Api\V1\ApprovalCenterController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ContractController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\CustomerDuplicateReviewController;
use App\Http\Controllers\Api\V1\DashboardSummaryController;
use App\Http\Controllers\Api\V1\DealController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\GoodsIssueController;
use App\Http\Controllers\Api\V1\GoodsReceiptController;
use App\Http\Controllers\Api\V1\InventoryBalanceController;
use App\Http\Controllers\Api\V1\InventoryTransactionController;
use App\Http\Controllers\Api\V1\KpiAssignmentController;
use App\Http\Controllers\Api\V1\KpiBundleController;
use App\Http\Controllers\Api\V1\KpiController;
use App\Http\Controllers\Api\V1\KpiPeriodClosureController;
use App\Http\Controllers\Api\V1\LeadController;
use App\Http\Controllers\Api\V1\LookupController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\PositionController;
use App\Http\Controllers\Api\V1\PrintTemplateController;
use App\Http\Controllers\Api\V1\ProductCatalogController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\PurchaseRequestController;
use App\Http\Controllers\Api\V1\QuotationController;
use App\Http\Controllers\Api\V1\RoleAdminController;
use App\Http\Controllers\Api\V1\SalesFulfillmentController;
use App\Http\Controllers\Api\V1\SalesMasterDataController;
use App\Http\Controllers\Api\V1\SalesOrderController;
use App\Http\Controllers\Api\V1\ServiceDeskController;
use App\Http\Controllers\Api\V1\SkuController;
use App\Http\Controllers\Api\V1\SlaPolicyController;
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

    Route::middleware(['jwt', \App\Http\Middleware\HideCostFields::class])->group(function () {
        Route::prefix('import-center')->group(function(){
            Route::get('/procurement/{kind}/template',[\App\Http\Controllers\Api\V1\ImportBatchController::class,'procurementTemplate']);
            Route::get('/organization/{kind}/template',[\App\Http\Controllers\Api\V1\ImportBatchController::class,'organizationTemplate']);
            Route::post('/organization/{kind}/preview',[\App\Http\Controllers\Api\V1\ImportBatchController::class,'organizationPreview'])->middleware('throttle:10,1');
            Route::post('/procurement/{kind}/preview',[\App\Http\Controllers\Api\V1\ImportBatchController::class,'procurementPreview'])->middleware('throttle:10,1');
            Route::get('/products/{kind}/template',[\App\Http\Controllers\Api\V1\ImportBatchController::class,'productTemplate']);
            Route::post('/products/{kind}/preview',[\App\Http\Controllers\Api\V1\ImportBatchController::class,'productPreview'])->middleware('throttle:10,1');
            Route::get('/customer-template',[\App\Http\Controllers\Api\V1\ImportBatchController::class,'customerTemplate']);
            Route::post('/customer-preview',[\App\Http\Controllers\Api\V1\ImportBatchController::class,'simpleCustomerPreview'])->middleware('throttle:10,1');
            Route::get('/contact-template',[\App\Http\Controllers\Api\V1\ImportBatchController::class,'contactTemplate']);
            Route::post('/contact-preview',[\App\Http\Controllers\Api\V1\ImportBatchController::class,'simpleContactPreview'])->middleware('throttle:10,1');
            Route::get('/lead-template',[\App\Http\Controllers\Api\V1\ImportBatchController::class,'leadTemplate']);
            Route::post('/lead-preview',[\App\Http\Controllers\Api\V1\ImportBatchController::class,'simpleLeadPreview'])->middleware('throttle:10,1');
            Route::get('/sources',[\App\Http\Controllers\Api\V1\ImportBatchController::class,'sources']);
            Route::post('/sources',[\App\Http\Controllers\Api\V1\ImportBatchController::class,'saveSource']);
            Route::put('/sources/{source}',[\App\Http\Controllers\Api\V1\ImportBatchController::class,'saveSource'])->whereNumber('source');
            Route::get('/batches',[\App\Http\Controllers\Api\V1\ImportBatchController::class,'index']);
            Route::get('/batches/{batch}/errors',[\App\Http\Controllers\Api\V1\ImportBatchController::class,'errors'])->whereNumber('batch')->middleware('throttle:10,1');
            Route::post('/batches',[\App\Http\Controllers\Api\V1\ImportBatchController::class,'preview'])->middleware('throttle:10,1');
            Route::get('/batches/{batch}',[\App\Http\Controllers\Api\V1\ImportBatchController::class,'show'])->whereNumber('batch');
            Route::post('/batches/{batch}/commit',[\App\Http\Controllers\Api\V1\ImportBatchController::class,'commit'])->whereNumber('batch')->middleware('throttle:10,1');
        });
        Route::get('/chat/users', [\App\Http\Controllers\Api\V1\ChatController::class, 'users']);
        Route::get('/chat', [\App\Http\Controllers\Api\V1\ChatController::class, 'index']);
        Route::post('/chat', [\App\Http\Controllers\Api\V1\ChatController::class, 'store'])->middleware('throttle:30,1');
        Route::get('/chat/{id}/messages', [\App\Http\Controllers\Api\V1\ChatController::class, 'messages'])->whereNumber('id');
        Route::post('/chat/{id}/messages', [\App\Http\Controllers\Api\V1\ChatController::class, 'send'])->whereNumber('id')->middleware('throttle:120,1');
        Route::post('/chat/{id}/read', [\App\Http\Controllers\Api\V1\ChatController::class, 'read'])->whereNumber('id');
        Route::get('/me', [MeController::class, 'show']);
        Route::get('/service-interests',[\App\Http\Controllers\Api\V1\ServiceInterestController::class,'index'])->middleware('permission:sales.deal.view');
        Route::post('/crm-intake/preview',[\App\Http\Controllers\Api\V1\CrmIntakeController::class,'preview'])->middleware(['permission:sales.deal.manage','throttle:10,1']);
        Route::post('/crm-intake/commit',[\App\Http\Controllers\Api\V1\CrmIntakeController::class,'commit'])->middleware(['permission:sales.deal.manage','throttle:10,1']);
        Route::get('/crm-intake/history',[\App\Http\Controllers\Api\V1\CrmIntakeController::class,'history'])->middleware('permission:sales.deal.manage');
        Route::post('/service-interests/import',[\App\Http\Controllers\Api\V1\ServiceInterestController::class,'import'])->middleware(['permission:sales.deal.manage','throttle:10,1']);
        Route::post('/service-interests',[\App\Http\Controllers\Api\V1\ServiceInterestController::class,'save'])->middleware('permission:sales.deal.manage');
        Route::put('/service-interests/{id}',[\App\Http\Controllers\Api\V1\ServiceInterestController::class,'save'])->whereNumber('id')->middleware('permission:sales.deal.manage');
        Route::get('/dashboard/summary', DashboardSummaryController::class);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/lookups/users', [LookupController::class, 'users']);
        Route::get('/lookups/sales-users', [LookupController::class, 'salesUsers']);
        Route::get('/lookups/deal-users', [LookupController::class, 'dealUsers'])->middleware('permission:sales.deal.view');
        Route::get('/lookups/departments', [LookupController::class, 'departments']);

        Route::middleware('permission:master.view')->group(function () {
            Route::get('/customer-groups', [\App\Http\Controllers\Api\V1\CustomerGroupController::class, 'index']);
            Route::get('/customer-groups/template', [\App\Http\Controllers\Api\V1\CustomerGroupController::class, 'template']);
            Route::post('/customer-groups/preview', [\App\Http\Controllers\Api\V1\CustomerGroupController::class, 'preview'])->middleware('throttle:10,1');
            Route::post('/customer-groups/commit', [\App\Http\Controllers\Api\V1\CustomerGroupController::class, 'commit'])->middleware('throttle:10,1');
            Route::post('/customer-groups', [\App\Http\Controllers\Api\V1\CustomerGroupController::class, 'save']);
            Route::put('/customer-groups/{id}', [\App\Http\Controllers\Api\V1\CustomerGroupController::class, 'save'])->whereNumber('id');
            Route::get('/customers', [CustomerController::class, 'index']);
            Route::get('/customer-contacts', [CustomerController::class, 'contacts']);
            Route::get('/customers/duplicates', [CustomerController::class, 'duplicates']);
            Route::get('/customers/care-board', [\App\Http\Controllers\Api\V1\CustomerCareController::class, 'board']);
            Route::get('/customers/{customer}', [CustomerController::class, 'show']);
            Route::get('/customers/{customer}/care', [\App\Http\Controllers\Api\V1\CustomerCareController::class, 'index']);
            Route::post('/customers/{customer}/care', [\App\Http\Controllers\Api\V1\CustomerCareController::class, 'store'])->middleware('permission:task.create');
            Route::post('/customers/{customer}/care/{task}/complete', [\App\Http\Controllers\Api\V1\CustomerCareController::class, 'complete'])->middleware('permission:task.create');
            Route::get('/suppliers', [SupplierController::class, 'index']);
            Route::get('/suppliers/{supplier}', [SupplierController::class, 'show']);
            Route::get('/skus', [SkuController::class, 'index']);
            Route::put('/catalog-sharing/{catalog}/{id}', [\App\Http\Controllers\Api\V1\CatalogSharingController::class, 'update'])->whereIn('catalog', ['customers', 'skus'])->whereNumber('id');
            Route::get('/skus/{sku}', [SkuController::class, 'show']);
            Route::get('/product-categories', [ProductCatalogController::class, 'categories']);
            Route::get('/product-brands', [ProductCatalogController::class, 'brands']);
            Route::get('/warehouses', [WarehouseController::class, 'index']);
            Route::get('/warehouses/{warehouse}', [WarehouseController::class, 'show']);
            Route::get('/inventory-balances', [InventoryBalanceController::class, 'index']);
            Route::get('/inventory-transactions', [InventoryTransactionController::class, 'index']);
            Route::get('/sales-master-data', [SalesMasterDataController::class, 'index']);
        });

        Route::group([], function () {
            Route::post('/customers', [CustomerController::class, 'store'])->middleware('permission:customer.manage');
            Route::get('/customer-import/template', [\App\Http\Controllers\Api\V1\CustomerImportController::class, 'template'])->middleware('permission:customer.manage');
            Route::post('/customer-import', [\App\Http\Controllers\Api\V1\CustomerImportController::class, 'store'])->middleware('permission:customer.manage');
            Route::post('/customer-contacts', [CustomerController::class, 'storeDirectoryContact'])->middleware('permission:customer.manage');
            Route::get('/directory-import/contacts/template',[\App\Http\Controllers\Api\V1\DirectoryImportController::class,'template'])->defaults('kind','contacts')->middleware('permission:customer.manage');
            Route::post('/directory-import/contacts',[\App\Http\Controllers\Api\V1\DirectoryImportController::class,'store'])->defaults('kind','contacts')->middleware('permission:customer.manage');
            Route::put('/customers/{customer}', [CustomerController::class, 'update'])->middleware('permission:customer.manage');
            Route::post('/customers/{customer}/contacts', [CustomerController::class, 'storeContact'])->middleware('permission:customer.manage');
            Route::put('/customers/{customer}/contacts/{contact}', [CustomerController::class, 'updateContact'])->middleware('permission:customer.manage');
            Route::delete('/customers/{customer}/contacts/{contact}', [CustomerController::class, 'deleteContact'])->middleware('permission:customer.manage');
            Route::post('/suppliers', [SupplierController::class, 'store'])->middleware('permission:supplier.manage');
            Route::put('/suppliers/{supplier}', [SupplierController::class, 'update'])->middleware('permission:supplier.manage');
            Route::post('/skus', [SkuController::class, 'store'])->middleware('permission:product.manage');
            Route::put('/skus/{sku}', [SkuController::class, 'update'])->middleware('permission:product.manage');
            Route::post('/product-categories', [ProductCatalogController::class, 'storeCategory'])->middleware('permission:product.manage');
            Route::post('/product-brands', [ProductCatalogController::class, 'storeBrand'])->middleware('permission:product.manage');
            Route::post('/warehouses', [WarehouseController::class, 'store'])->middleware('permission:warehouse.manage');
            Route::put('/warehouses/{warehouse}', [WarehouseController::class, 'update'])->middleware('permission:warehouse.manage');
            Route::post('/sales-master-data/stages', [SalesMasterDataController::class, 'storeStage'])->middleware('permission:sales.master.manage');
            Route::put('/sales-master-data/stages/{stage}', [SalesMasterDataController::class, 'updateStage'])->middleware('permission:sales.master.manage');
            Route::post('/sales-master-data/sources', [SalesMasterDataController::class, 'storeSource'])->middleware('permission:sales.master.manage');
            Route::put('/sales-master-data/sources/{source}', [SalesMasterDataController::class, 'updateSource'])->middleware('permission:sales.master.manage');
            Route::post('/sales-master-data/price-books', [SalesMasterDataController::class, 'storePriceBook'])->middleware('permission:sales.master.manage');
            Route::put('/sales-master-data/price-books/{priceBook}', [SalesMasterDataController::class, 'updatePriceBook'])->middleware('permission:sales.master.manage');
            Route::post('/sales-master-data/payment-terms', [SalesMasterDataController::class, 'storePaymentTerm'])->middleware('permission:sales.master.manage');
            Route::put('/sales-master-data/payment-terms/{paymentTerm}', [SalesMasterDataController::class, 'updatePaymentTerm'])->middleware('permission:sales.master.manage');
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
        });

        Route::middleware('permission:alert.view')->group(function () {
            Route::get('/alerts', [AlertController::class, 'index']);
        });

        Route::post('/tasks/{task}/status', [TaskController::class, 'updateStatus'])->middleware('permission:task.update');
        Route::post('/alerts/{alert}/status', [AlertController::class, 'updateStatus'])->middleware('permission:alert.update');

        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::get('/approval-center', ApprovalCenterController::class);
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
        Route::get('/print-templates/active', [PrintTemplateController::class, 'active']);
        Route::get('/print-templates/choices', [PrintTemplateController::class, 'choices']);

        Route::middleware('permission:audit.view')->group(function () {
            Route::get('/audit-logs', [AuditLogController::class, 'index']);
            Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show']);
        });

        Route::get('/kpi/overview', [KpiController::class, 'overview']);
        Route::get('/kpi/mine', [KpiAssignmentController::class, 'mine']);
        Route::get('/kpi/period-closures/mine', [KpiPeriodClosureController::class, 'mine']);
        Route::post('/kpi/period-closures/{kpiPeriodClosure}/respond', [KpiPeriodClosureController::class, 'respond']);
        Route::get('/kpi/employee-report', [KpiController::class, 'employeeReport']);
        Route::get('/kpi/employee-report/export', [KpiController::class, 'exportEmployeeReport']);
        Route::get('/business-reports', [\App\Http\Controllers\Api\V1\BusinessReportController::class, 'index']);
        Route::get('/business-reports/export', [\App\Http\Controllers\Api\V1\BusinessReportController::class, 'export']);
        Route::get('/kpi/snapshots', [KpiController::class, 'snapshots']);
        Route::get('/kpi/exceptions', [KpiController::class, 'exceptions']);
        Route::get('/kpi/adjustments', [KpiController::class, 'adjustments']);
        Route::get('/kpi/adjustments/summary', [KpiController::class, 'adjustmentSummary']);
        Route::post('/kpi/exceptions', [KpiController::class, 'storeException']);

        Route::post('/kpi/adjustments', [KpiController::class, 'storeAdjustment'])->middleware('permission:kpi.adjustment.create');
        Route::post('/kpi/adjustments/{kpiAdjustment}/review', [KpiController::class, 'reviewAdjustment'])->middleware('permission:kpi.adjustment.review');
        Route::post('/kpi/exceptions/{kpiException}/review', [KpiController::class, 'reviewException'])->middleware('permission:kpi.exception.review');
        Route::group([], function () {
            Route::get('/kpi/period-closures', [KpiPeriodClosureController::class, 'index'])->middleware('permission:kpi.lock');
            Route::post('/kpi/period-closures/close', [KpiPeriodClosureController::class, 'close'])->middleware('permission:kpi.lock');
            Route::post('/kpi/period-closures/{kpiPeriodClosure}/finalize', [KpiPeriodClosureController::class, 'finalize'])->middleware('permission:kpi.lock');
            Route::post('/kpi/period-closures/{kpiPeriodClosure}/reopen', [KpiPeriodClosureController::class, 'reopen'])->middleware('permission:kpi.lock');
            Route::post('/kpi/period-acknowledgements/{kpiPeriodAcknowledgement}/resolve', [KpiPeriodClosureController::class, 'resolve'])->middleware('permission:kpi.lock');
            Route::get('/kpi/bundles', [KpiBundleController::class, 'index'])->middleware('permission:kpi.configure');
            Route::post('/kpi/standard-setup', [KpiBundleController::class, 'standardSetup'])->middleware('permission:kpi.configure');
            Route::post('/kpi/bundles', [KpiBundleController::class, 'store'])->middleware('permission:kpi.configure');
            Route::put('/kpi/bundles/{kpiBundle}', [KpiBundleController::class, 'update'])->middleware('permission:kpi.configure');
            Route::post('/kpi/bundles/{kpiBundle}/publish', [KpiBundleController::class, 'publish'])->middleware('permission:kpi.configure');
            Route::post('/kpi/bundles/{kpiBundle}/duplicate', [KpiBundleController::class, 'duplicate'])->middleware('permission:kpi.configure');
            Route::post('/kpi/bundles/{kpiBundle}/apply', [KpiBundleController::class, 'apply'])->middleware('permission:kpi.configure');
            Route::get('/kpi/assignments', [KpiAssignmentController::class, 'index'])->middleware('permission:kpi.configure');
            Route::post('/kpi/assignments/preview', [KpiAssignmentController::class, 'preview'])->middleware('permission:kpi.configure');
            Route::post('/kpi/assignments', [KpiAssignmentController::class, 'store'])->middleware('permission:kpi.configure');
            Route::put('/kpi/assignments/{kpiAssignment}', [KpiAssignmentController::class, 'update'])->middleware('permission:kpi.configure');
            Route::post('/kpi/assignments/{kpiAssignment}/lock', [KpiAssignmentController::class, 'lock'])->middleware('permission:kpi.lock');
            Route::get('/kpi/definitions', [KpiController::class, 'definitions'])->middleware('permission:kpi.configure');
            Route::get('/kpi/targets', [KpiController::class, 'targets'])->middleware('permission:kpi.configure');
            Route::post('/kpi/snapshots/calculate', [KpiController::class, 'calculate'])->middleware('permission:kpi.configure');
            Route::post('/kpi/definitions', [KpiController::class, 'storeDefinition'])->middleware('permission:kpi.configure');
            Route::put('/kpi/definitions/{kpiDefinition}', [KpiController::class, 'updateDefinition'])->middleware('permission:kpi.configure');
            Route::post('/kpi/targets', [KpiController::class, 'storeTarget'])->middleware('permission:kpi.configure');
            Route::put('/kpi/targets/{kpiTarget}', [KpiController::class, 'updateTarget'])->middleware('permission:kpi.configure');
            Route::post('/kpi/targets/{kpiTarget}/lock', [KpiController::class, 'lockTarget'])->middleware('permission:kpi.lock');
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
            Route::post('/users/{user}/activate-imported', [UserAdminController::class, 'activateImported'])->middleware('throttle:10,1');
            Route::get('/users/{user}/handover', [\App\Http\Controllers\Api\V1\PersonnelHandoverController::class, 'preview']);
            Route::post('/users/{user}/handover', [\App\Http\Controllers\Api\V1\PersonnelHandoverController::class, 'transfer']);
            Route::get('/departments', [DepartmentController::class, 'index']);
            Route::post('/departments', [DepartmentController::class, 'store']);
            Route::put('/departments/{department}', [DepartmentController::class, 'update']);
            Route::get('/positions', [PositionController::class, 'index']);
            Route::post('/positions', [PositionController::class, 'store']);
            Route::put('/positions/{position}', [PositionController::class, 'update']);
            Route::get('/print-templates', [PrintTemplateController::class, 'index']);
            Route::get('/print-templates/reference/{module}', [PrintTemplateController::class, 'reference'])
                ->where('module', 'quotation|sales_order|goods_issue|contract|delivery_acceptance');
            Route::get('/print-templates/{printTemplate}/original', [PrintTemplateController::class, 'original']);
            Route::post('/print-templates', [PrintTemplateController::class, 'store']);
            Route::post('/print-templates/{printTemplate}', [PrintTemplateController::class, 'update']);
            Route::put('/print-templates/{printTemplate}', [PrintTemplateController::class, 'update']);
            Route::delete('/print-templates/{printTemplate}', [PrintTemplateController::class, 'destroy']);
        });

        Route::middleware('permission:role.manage')->group(function () {
            Route::get('/roles', [RoleAdminController::class, 'index']);
            Route::post('/roles', [RoleAdminController::class, 'store']);
            Route::post('/roles/{role}/permissions', [RoleAdminController::class, 'syncPermissions']);
            Route::get('/permissions', [PermissionController::class, 'index']);
        });

        Route::middleware('permission:sales.lead.view')->group(function () {
            Route::get('/leads', [LeadController::class, 'index']);
            Route::get('/leads/{lead}', [LeadController::class, 'show']);
        });
        Route::middleware('permission:sales.lead.manage')->group(function () {
            Route::get('/directory-import/leads/template',[\App\Http\Controllers\Api\V1\DirectoryImportController::class,'template'])->defaults('kind','leads');
            Route::post('/directory-import/leads',[\App\Http\Controllers\Api\V1\DirectoryImportController::class,'store'])->defaults('kind','leads');
            Route::post('/leads', [LeadController::class, 'store']);
            Route::post('/leads/{lead}/assign', [LeadController::class, 'assign']);
            Route::post('/leads/{lead}/follow-ups', [LeadController::class, 'createFollowUp']);
            Route::post('/leads/{lead}/follow-ups/{task}/status', [LeadController::class, 'updateFollowUpStatus']);
            Route::post('/leads/{lead}/qualify', [LeadController::class, 'qualify']);
        });

        Route::middleware('permission:sales.deal.view')->group(function () {
            Route::get('/deals', [DealController::class, 'index']);
            Route::get('/deals/{deal}', [DealController::class, 'show']);
        });
        Route::middleware('permission:sales.deal.manage')->group(function () {
            Route::post('/deals', [DealController::class, 'store']);
            Route::post('/deals/{deal}/transition', [DealController::class, 'transition']);
            Route::put('/deals/{deal}/items', [DealController::class, 'syncItems']);
            Route::put('/deals/{deal}/needs', [DealController::class, 'updateNeeds']);
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
            Route::get('/delivery-records', [SalesFulfillmentController::class, 'deliveryRecords']);
            Route::get('/delivery-records/{delivery}/word', [SalesFulfillmentController::class, 'exportDeliveryWord']);
            Route::get('/delivery-records/{delivery}/acceptance-file', [SalesFulfillmentController::class, 'acceptanceFile']);
        });

        Route::middleware('permission:sales.delivery.confirm')->group(function () {
            Route::post('/sales-orders/{salesOrder}/deliveries', [SalesFulfillmentController::class, 'confirmDelivery']);
        });

        Route::middleware('permission:sales.delivery.accept')->group(function () {
            Route::post('/deliveries/{delivery}/acceptance', [SalesFulfillmentController::class, 'confirmAcceptance']);
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

        Route::middleware('permission:procurement.pr.view')->group(function () {
            Route::get('/purchase-requests', [PurchaseRequestController::class, 'index']);
            Route::get('/purchase-requests/{purchaseRequest}', [PurchaseRequestController::class, 'show']);
        });
        Route::middleware('permission:procurement.pr.create')->group(function () {
            Route::post('/purchase-requests', [PurchaseRequestController::class, 'store']);
        });
        Route::middleware('permission:procurement.pr.approve')->group(function () {
            Route::post('/purchase-requests/{purchaseRequest}/approve', [PurchaseRequestController::class, 'approve']);
            Route::post('/purchase-requests/{purchaseRequest}/reject', [PurchaseRequestController::class, 'reject']);
        });

        Route::middleware('permission:procurement.sq.view')->group(function () {
            Route::get('/supplier-quotations/bootstrap', [SupplierQuotationController::class, 'bootstrap']);
            Route::get('/supplier-quotations', [SupplierQuotationController::class, 'index']);
            Route::get('/supplier-quotations/{supplierQuotation}', [SupplierQuotationController::class, 'show']);
            Route::get('/supplier-quotations/{supplierQuotation}/document', [SupplierQuotationController::class, 'document']);
        });
        Route::middleware('permission:procurement.sq.manage')->group(function () {
            Route::post('/supplier-quotations', [SupplierQuotationController::class, 'store']);
            Route::post('/supplier-quotations/{supplierQuotation}/document', [SupplierQuotationController::class, 'uploadDocument']);
        });
        Route::middleware('permission:procurement.po.create')->group(function () {
            Route::post('/supplier-quotations/{supplierQuotation}/select', [SupplierQuotationController::class, 'select']);
            Route::post('/purchase-orders', [PurchaseOrderController::class, 'store']);
        });
        Route::middleware('permission:procurement.po.view')->group(function () {
            Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
            Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show']);
        });
        Route::middleware('permission:procurement.po.approve')->group(function () {
            Route::post('/supplier-quotations/{supplierQuotation}/approval-flow', [SupplierQuotationController::class, 'configureApprovalFlow']);
            Route::post('/purchase-orders/{purchaseOrder}/approval-flow', [PurchaseOrderController::class, 'configureApprovalFlow']);
            Route::post('/purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve']);
            Route::post('/purchase-orders/{purchaseOrder}/reject', [PurchaseOrderController::class, 'reject']);
        });

        Route::middleware('permission:inventory.receipt.view')->group(function () {
            Route::get('/goods-receipts', [GoodsReceiptController::class, 'index']);
            Route::get('/goods-receipts/{goodsReceipt}', [GoodsReceiptController::class, 'show']);
        });
        Route::middleware('permission:inventory.receipt.create')->group(function () {
            Route::post('/goods-receipts', [GoodsReceiptController::class, 'store']);
        });
        Route::middleware('permission:inventory.receipt.confirm')->group(function () {
            Route::post('/goods-receipts/{goodsReceipt}/confirm', [GoodsReceiptController::class, 'confirm']);
        });

        Route::middleware('permission:inventory.issue.view')->group(function () {
            Route::get('/goods-issues', [GoodsIssueController::class, 'index']);
            Route::get('/goods-issues/{goodsIssue}', [GoodsIssueController::class, 'show']);
            Route::get('/goods-issues/{goodsIssue}/word', [GoodsIssueController::class, 'exportWord']);
        });
        Route::middleware('permission:inventory.issue.create')->group(function () {
            Route::post('/goods-issues', [GoodsIssueController::class, 'store']);
        });
        Route::middleware('permission:inventory.issue.confirm')->group(function () {
            Route::post('/goods-issues/{goodsIssue}/confirm', [GoodsIssueController::class, 'confirm']);
        });
        Route::middleware('permission:inventory.stocktake.view')->group(function () {
            Route::get('/stock-takes', [StockTakeController::class, 'index']);
            Route::get('/stock-takes/{stockTake}', [StockTakeController::class, 'show']);
        });
        Route::middleware('permission:inventory.stocktake.create')->group(function () {
            Route::post('/stock-takes', [StockTakeController::class, 'store']);
        });
        Route::middleware('permission:inventory.stocktake.confirm')->group(function () {
            Route::post('/stock-takes/{stockTake}/confirm', [StockTakeController::class, 'confirm']);
        });

        Route::middleware('permission:service.ticket.view')->group(function () {
            Route::get('/service-tickets', [ServiceDeskController::class, 'tickets']);
            Route::get('/service-tickets/{ticket}', [ServiceDeskController::class, 'showTicket']);
            Route::get('/service-tickets/{ticket}/attachments/{attachment}', [\App\Http\Controllers\Api\V1\TicketAttachmentController::class, 'download']);
            Route::get('/warranty-claims', [ServiceDeskController::class, 'warranties']);
            Route::get('/service-desk/lookups', [ServiceDeskController::class, 'lookups']);
            Route::get('/service-desk/documents', [ServiceDeskController::class, 'documents']);
            Route::get('/warranty-customers/lookup/{taxCode}', [ServiceDeskController::class, 'lookupWarrantyCustomer']);
        });

        Route::middleware('permission:service.ticket.manage')->group(function () {
            Route::get('/warranty-import/template', [\App\Http\Controllers\Api\V1\WarrantyImportController::class, 'template']);
            Route::post('/warranty-import', [\App\Http\Controllers\Api\V1\WarrantyImportController::class, 'store']);
            Route::post('/service-tickets', [ServiceDeskController::class, 'storeTicket']);
            Route::post('/service-tickets/{ticket}/attachments', [\App\Http\Controllers\Api\V1\TicketAttachmentController::class, 'store']);
            Route::post('/service-tickets/{ticket}/worklogs', [ServiceDeskController::class, 'addWorklog']);
            Route::put('/service-tickets/{ticket}', [ServiceDeskController::class, 'updateTicket']);
            Route::post('/service-tickets/{ticket}/sla-clock', [ServiceDeskController::class, 'clock']);
            Route::post('/warranty-claims', [ServiceDeskController::class, 'storeWarranty']);
        });
    });
});
