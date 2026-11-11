<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProcurementDocumentLinksTest extends TestCase
{
    public function test_old_document_links_redirect_to_the_correct_workspace(): void
    {
        $this->get('/purchase-orders/2')->assertRedirect('/purchase-orders?order=2');
        $this->get('/purchase-requests/2')->assertRedirect('/purchase-requests?request=2');
        $this->get('/goods-receipts/2')->assertRedirect('/goods-receipts?receipt=2');
        $this->get('/purchase-orders/not-an-id')->assertNotFound();
    }
}
