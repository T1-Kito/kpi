<?php

return [
    // Only explicitly registered domain events may be acknowledged by the outbox.
    // A listener throwing an exception keeps the row failed for a safe retry.
    'supported' => [
        'ContractCreated', 'CustomerCreated', 'CustomerPaymentRecorded',
        'DealCreated', 'DealStageChanged', 'DealWon', 'DeliveryConfirmed',
        'GoodsIssueConfirmed', 'GoodsReceiptConfirmed', 'LeadAssigned',
        'LeadCreated', 'LeadQualified', 'PurchaseOrderApproved',
        'PurchaseOrderCreated', 'PurchaseOrderLate', 'PurchaseOrderRejected',
        'PurchaseRequestApproved', 'PurchaseRequestCreated', 'PurchaseRequestRejected',
        'QuotationApproved', 'QuotationCreated', 'QuotationDeleted',
        'QuotationMarginLow', 'QuotationUpdated', 'SalesInvoiceIssued',
        'SalesOrderConfirmed', 'SalesOrderCreated', 'SalesOrderReservedAfterReceipt',
        'SalesOrderStockShortage', 'SalesOrderSupplierDirect', 'ServiceTicketCreated',
        'ServiceTicketStatusChanged', 'SkuCreated', 'SupplierCreated',
        'TaskCreated', 'TaskOverdue', 'TaskStatusChanged', 'WarehouseCreated',
        'WarrantyRegistered',
    ],
];
