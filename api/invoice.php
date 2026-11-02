<?php

/**
 * Pure PHP API: Provider Invoice Generator & Downloader
 * Generates professional, compliant tax invoices for marketplace providers & buyers
 * Based on main production server host (https://knowlipop.shop).
 */

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/orders.php';

function getServerBaseUrl(): string
{
    // Main production server host
    return 'https://knowlipop.shop';
}

function cleanServerUrl(string $url): string
{
    return preg_replace('#^https?://(?:localhost|127\.0\.0\.1)(?::\d+)?#i', 'https://knowlipop.shop', $url);
}

function numberToWords(float $amount): string
{
    $ones = [
        0 => 'Zero', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five',
        6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine', 10 => 'Ten', 11 => 'Eleven',
        12 => 'Twelve', 13 => 'Thirteen', 14 => 'Fourteen', 15 => 'Fifteen', 16 => 'Sixteen',
        17 => 'Seventeen', 18 => 'Eighteen', 19 => 'Nineteen'
    ];
    $tens = [
        2 => 'Twenty', 3 => 'Thirty', 4 => 'Forty', 5 => 'Fifty',
        6 => 'Sixty', 7 => 'Seventy', 8 => 'Eighty', 9 => 'Ninety'
    ];

    $rupees = (int) floor($amount);
    $paise = (int) round(($amount - $rupees) * 100);

    if ($rupees === 0) {
        $words = 'Zero';
    } else {
        $words = convertTriplets($rupees, $ones, $tens);
    }

    $result = 'INR ' . trim($words) . ' Rupees';
    if ($paise > 0) {
        $result .= ' and ' . convertTriplets($paise, $ones, $tens) . ' Paise';
    }
    return $result . ' Only';
}

function convertTriplets(int $num, array $ones, array $tens): string
{
    if ($num >= 10000000) {
        return convertTriplets((int) floor($num / 10000000), $ones, $tens) . ' Crore ' . convertTriplets($num % 10000000, $ones, $tens);
    }
    if ($num >= 100000) {
        return convertTriplets((int) floor($num / 100000), $ones, $tens) . ' Lakh ' . convertTriplets($num % 100000, $ones, $tens);
    }
    if ($num >= 1000) {
        return convertTriplets((int) floor($num / 1000), $ones, $tens) . ' Thousand ' . convertTriplets($num % 1000, $ones, $tens);
    }
    if ($num >= 100) {
        return $ones[(int) floor($num / 100)] . ' Hundred ' . convertTriplets($num % 100, $ones, $tens);
    }
    if ($num >= 20) {
        return $tens[(int) floor($num / 10)] . ($num % 10 > 0 ? ' ' . $ones[$num % 10] : '');
    }
    if ($num > 0) {
        return $ones[$num];
    }
    return '';
}

function apiGenerateOrderInvoice(PDO $pdo, mixed $idOrNumber): void
{
    ensureOrdersSchema($pdo);

    // Fetch Order by numeric ID or order_number string
    if (is_numeric($idOrNumber)) {
        $stmt = $pdo->prepare("SELECT * FROM `orders` WHERE id = :id OR order_number = :num LIMIT 1");
        $stmt->execute([':id' => (int) $idOrNumber, ':num' => (string) $idOrNumber]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM `orders` WHERE order_number = :num LIMIT 1");
        $stmt->execute([':num' => (string) $idOrNumber]);
    }
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        errorResponse('Order not found.', [], 404);
    }
    $orderId = (int) $order['id'];

    // Fetch Order Items
    $itemStmt = $pdo->prepare("SELECT * FROM `order_items` WHERE order_id = :order_id ORDER BY id ASC");
    $itemStmt->execute([':order_id' => $orderId]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    // Normalize image URLs to main host domain (https://knowlipop.shop)
    foreach ($items as &$item) {
        if (!empty($item['product_image'])) {
            $item['product_image'] = cleanServerUrl($item['product_image']);
        }
    }

    $serverUrl = getServerBaseUrl();

    // Resolve Provider / Seller details from order products
    $provider = [
        'name' => 'Knowlipop Marketplace Services',
        'shop_name' => 'Knowlipop Direct Merchant Services',
        'phone' => '+91 96334 26254',
        'email' => 'support@knowlipop.shop',
        'server_portal' => $serverUrl,
        'address' => 'Technology Innovation Hub, MG Road',
        'landmark' => 'Electronic City Phase 1',
        'city' => 'Bengaluru',
        'state' => 'Karnataka',
        'country' => 'India',
        'gstin' => '29AABCU9603R1ZM',
        'pan' => 'AABCU9603R',
        'cin' => 'U72900KA2026PTC109821'
    ];

    // Resolve Provider / Seller details directly from order's seller_id or products
    $sellerId = !empty($order['seller_id']) ? (int)$order['seller_id'] : null;
    if (!$sellerId && !empty($items)) {
        $sellerId = !empty($items[0]['seller_id']) ? (int)$items[0]['seller_id'] : null;
    }

    if ($sellerId) {
        $uStmt = $pdo->prepare("
            SELECT name, seller_name, phone, email, address, landmark, state, country
            FROM users
            WHERE id = :uid
            LIMIT 1
        ");
        $uStmt->execute([':uid' => $sellerId]);
        $sellerRow = $uStmt->fetch(PDO::FETCH_ASSOC);
        if ($sellerRow) {
            $provider['name'] = $sellerRow['seller_name'] ?: $sellerRow['name'];
            $provider['shop_name'] = $sellerRow['seller_name'] ?: ($sellerRow['name'] . ' Store');
            if (!empty($sellerRow['phone'])) $provider['phone'] = $sellerRow['phone'];
            if (!empty($sellerRow['email'])) $provider['email'] = $sellerRow['email'];
            if (!empty($sellerRow['address'])) $provider['address'] = $sellerRow['address'];
            if (!empty($sellerRow['landmark'])) $provider['landmark'] = $sellerRow['landmark'];
            if (!empty($sellerRow['state'])) $provider['state'] = $sellerRow['state'];
            if (!empty($sellerRow['country'])) $provider['country'] = $sellerRow['country'];
        }
    } elseif (!empty($items)) {
        $firstProdId = $items[0]['product_id'] ?? null;
        if ($firstProdId) {
            $pStmt = $pdo->prepare("
                SELECT u.name, u.seller_name, u.phone, u.email, u.address, u.landmark, u.state, u.country
                FROM products p
                JOIN users u ON p.seller_id = u.id
                WHERE p.id = :pid
                LIMIT 1
            ");
            $pStmt->execute([':pid' => $firstProdId]);
            $sellerRow = $pStmt->fetch(PDO::FETCH_ASSOC);
            if ($sellerRow) {
                $provider['name'] = $sellerRow['seller_name'] ?: $sellerRow['name'];
                $provider['shop_name'] = $sellerRow['seller_name'] ?: ($sellerRow['name'] . ' Store');
                if (!empty($sellerRow['phone'])) $provider['phone'] = $sellerRow['phone'];
                if (!empty($sellerRow['email'])) $provider['email'] = $sellerRow['email'];
                if (!empty($sellerRow['address'])) $provider['address'] = $sellerRow['address'];
                if (!empty($sellerRow['landmark'])) $provider['landmark'] = $sellerRow['landmark'];
                if (!empty($sellerRow['state'])) $provider['state'] = $sellerRow['state'];
                if (!empty($sellerRow['country'])) $provider['country'] = $sellerRow['country'];
            }
        }
    }

    $isDownload = !empty($_GET['download']) && in_array(strtolower($_GET['download']), ['1', 'true', 'yes', 'pdf']);
    $isJson = isset($_GET['format']) && $_GET['format'] === 'json';

    $invoiceNumber = 'INV-' . date('Ymd', strtotime($order['created_at'])) . '-' . str_pad((string)$order['id'], 5, '0', STR_PAD_LEFT);
    $invoiceDate = date('d M Y', strtotime($order['created_at']));
    $dueDate = date('d M Y', strtotime($order['created_at'] . ' + 7 days'));

    if ($isJson) {
        jsonResponse([
            'invoice' => [
                'invoice_number' => $invoiceNumber,
                'invoice_date' => $invoiceDate,
                'due_date' => $dueDate,
                'order' => $order,
                'provider' => $provider,
                'items' => $items,
                'server_url' => $serverUrl,
                'verification_url' => "{$serverUrl}/invoice/{$orderId}",
                'amount_in_words' => numberToWords((float) $order['total_amount']),
                'download_url' => "{$serverUrl}/api/orders/{$orderId}/invoice?download=1"
            ]
        ]);
        return;
    }

    // Set headers
    if ($isDownload) {
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="Invoice-' . $order['order_number'] . '.html"');
    } else {
        header('Content-Type: text/html; charset=utf-8');
    }

    renderInvoiceHtmlTemplate($order, $items, $provider, $invoiceNumber, $invoiceDate, $dueDate, $serverUrl);
    exit;
}

function renderInvoiceHtmlTemplate(array $order, array $items, array $provider, string $invoiceNumber, string $invoiceDate, string $dueDate, string $serverUrl): void
{
    $subtotal = (float) $order['subtotal'];
    $deliveryFee = (float) $order['delivery_fee'];
    $discount = (float) $order['discount'];
    $totalAmount = (float) $order['total_amount'];
    $amountWords = numberToWords($totalAmount);

    $status = strtolower($order['order_status'] ?? 'pending');
    $badgeText = strtoupper($status);
    $badgeClass = ($status === 'delivered' || $status === 'confirmed' || $order['payment_status'] === 'paid') ? 'badge-paid' : 'badge-pending';
    if ($order['payment_status'] === 'paid') {
        $badgeText = 'PAID IN FULL';
        $badgeClass = 'badge-paid';
    } elseif ($status === 'cancelled') {
        $badgeText = 'CANCELLED';
        $badgeClass = 'badge-cancelled';
    }

    $verificationUrl = "{$serverUrl}/invoice/{$order['id']}";
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>Invoice - <?= htmlspecialchars($order['order_number']) ?> - <?= htmlspecialchars($provider['shop_name']) ?></title>
      <link rel="preconnect" href="https://fonts.googleapis.com">
      <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
      <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">

      <style>
        :root {
          --primary: #4338ca;
          --primary-light: #e0e7ff;
          --text-main: #0f172a;
          --text-muted: #64748b;
          --border-color: #e2e8f0;
          --bg-light: #f8fafc;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
          font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
          background: #f1f5f9;
          color: var(--text-main);
          padding: 30px 16px;
          min-height: 100vh;
          display: flex;
          flex-direction: column;
          align-items: center;
        }

        /* Top Action Bar (hidden when printing) */
        .action-bar {
          width: 100%;
          max-width: 860px;
          display: flex;
          justify-content: space-between;
          align-items: center;
          margin-bottom: 24px;
          background: #ffffff;
          border: 1px solid #cbd5e1;
          border-radius: 12px;
          padding: 14px 20px;
          box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }
        .action-btns {
          display: flex;
          gap: 10px;
        }
        .btn {
          display: inline-flex;
          align-items: center;
          gap: 8px;
          padding: 10px 18px;
          border-radius: 8px;
          font-family: 'Plus Jakarta Sans', sans-serif;
          font-size: 13px;
          font-weight: 700;
          cursor: pointer;
          text-decoration: none;
          transition: all 0.2s;
          border: none;
        }
        .btn-print {
          background: #4f46e5;
          color: #ffffff;
          box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
        }
        .btn-print:hover { background: #4338ca; }
        .btn-download {
          background: #0284c7;
          color: #ffffff;
        }
        .btn-download:hover { background: #0369a1; }
        .btn-back {
          background: #f8fafc;
          border: 1px solid #cbd5e1;
          color: #334155;
        }
        .btn-back:hover { background: #e2e8f0; }

        /* Invoice Sheet Paper */
        .invoice-sheet {
          width: 100%;
          max-width: 860px;
          background: #ffffff;
          border: 1px solid #e2e8f0;
          border-radius: 16px;
          padding: 48px;
          box-shadow: 0 15px 35px rgba(0,0,0,0.06);
          position: relative;
          overflow: hidden;
        }

        /* Watermark */
        .watermark {
          position: absolute;
          top: 50%;
          left: 50%;
          transform: translate(-50%, -50%) rotate(-30deg);
          font-size: 80px;
          font-weight: 900;
          color: rgba(99, 102, 241, 0.04);
          letter-spacing: 8px;
          pointer-events: none;
          text-transform: uppercase;
          white-space: nowrap;
          z-index: 1;
        }

        /* Invoice Header */
        .invoice-header {
          display: flex;
          justify-content: space-between;
          align-items: flex-start;
          border-bottom: 2px solid #e2e8f0;
          padding-bottom: 28px;
          margin-bottom: 30px;
          position: relative;
          z-index: 2;
        }
        .provider-brand {
          display: flex;
          align-items: center;
          gap: 12px;
          margin-bottom: 8px;
        }
        .provider-logo-icon {
          width: 48px;
          height: 48px;
          background: linear-gradient(135deg, #4f46e5, #06b6d4);
          border-radius: 12px;
          color: #ffffff;
          display: flex;
          align-items: center;
          justify-content: center;
          font-size: 24px;
        }
        .provider-name {
          font-size: 20px;
          font-weight: 800;
          color: #0f172a;
          letter-spacing: -0.5px;
        }
        .provider-address {
          font-size: 13px;
          color: var(--text-muted);
          line-height: 1.5;
          max-width: 380px;
          margin-top: 6px;
        }
        .tax-reg-info {
          font-family: 'JetBrains Mono', monospace;
          font-size: 11px;
          color: #64748b;
          margin-top: 6px;
        }

        .invoice-meta {
          text-align: right;
        }
        .invoice-badge-title {
          font-size: 26px;
          font-weight: 900;
          color: #312e81;
          letter-spacing: -0.5px;
        }
        .invoice-num {
          font-family: 'JetBrains Mono', monospace;
          font-size: 15px;
          font-weight: 700;
          color: #4f46e5;
          margin-top: 4px;
        }
        .invoice-dates {
          font-size: 12px;
          color: var(--text-muted);
          margin-top: 8px;
          line-height: 1.6;
        }

        .status-stamp {
          display: inline-block;
          font-size: 12px;
          font-weight: 800;
          text-transform: uppercase;
          letter-spacing: 1px;
          padding: 6px 16px;
          border-radius: 999px;
          margin-top: 10px;
        }
        .badge-paid {
          background: #ecfdf5;
          border: 1px solid #a7f3d0;
          color: #059669;
        }
        .badge-pending {
          background: #fffbeb;
          border: 1px solid #fde68a;
          color: #d97706;
        }
        .badge-cancelled {
          background: #fef2f2;
          border: 1px solid #fecaca;
          color: #dc2626;
        }

        /* Billing Parties Box */
        .parties-grid {
          display: grid;
          grid-template-columns: 1fr 1fr;
          gap: 24px;
          background: var(--bg-light);
          border: 1px solid var(--border-color);
          border-radius: 12px;
          padding: 20px;
          margin-bottom: 30px;
          position: relative;
          z-index: 2;
        }
        .party-col-title {
          font-size: 11px;
          font-weight: 800;
          text-transform: uppercase;
          letter-spacing: 0.5px;
          color: #6366f1;
          margin-bottom: 8px;
        }
        .party-name {
          font-size: 16px;
          font-weight: 800;
          color: #0f172a;
        }
        .party-details {
          font-size: 13px;
          color: #475569;
          line-height: 1.5;
          margin-top: 4px;
        }

        /* Items Table */
        .table-wrap {
          margin-bottom: 28px;
          position: relative;
          z-index: 2;
        }
        table {
          width: 100%;
          border-collapse: collapse;
          font-size: 13px;
        }
        th {
          background: #f1f5f9;
          color: #475569;
          font-weight: 800;
          text-transform: uppercase;
          font-size: 11px;
          letter-spacing: 0.5px;
          padding: 12px 14px;
          border-top: 1px solid #cbd5e1;
          border-bottom: 2px solid #cbd5e1;
          text-align: left;
        }
        th.text-right, td.text-right {
          text-align: right;
        }
        th.text-center, td.text-center {
          text-align: center;
        }
        td {
          padding: 14px;
          border-bottom: 1px solid #e2e8f0;
          color: #1e293b;
          vertical-align: middle;
        }
        .item-title {
          font-weight: 700;
          color: #0f172a;
          font-size: 14px;
        }
        .item-sku {
          font-family: 'JetBrains Mono', monospace;
          font-size: 11px;
          color: #94a3b8;
          margin-top: 2px;
        }
        .price-mono {
          font-family: 'JetBrains Mono', monospace;
          font-weight: 600;
        }

        /* Calculation Breakdown & Signatures */
        .bottom-section {
          display: grid;
          grid-template-columns: 1fr 340px;
          gap: 32px;
          align-items: start;
          position: relative;
          z-index: 2;
        }
        .notes-and-terms {
          font-size: 12px;
          color: var(--text-muted);
          line-height: 1.6;
        }
        .terms-title {
          font-size: 12px;
          font-weight: 800;
          color: #0f172a;
          text-transform: uppercase;
          margin-bottom: 6px;
        }
        .signature-box {
          margin-top: 24px;
          padding-top: 12px;
          border-top: 1px dashed #cbd5e1;
          display: flex;
          align-items: center;
          gap: 16px;
        }
        .signature-seal {
          width: 58px;
          height: 58px;
          border: 2px dashed #6366f1;
          border-radius: 50%;
          display: flex;
          align-items: center;
          justify-content: center;
          font-size: 10px;
          font-weight: 800;
          color: #4f46e5;
          text-transform: uppercase;
          text-align: center;
          line-height: 1.1;
        }

        .summary-card {
          background: var(--bg-light);
          border: 1px solid var(--border-color);
          border-radius: 12px;
          padding: 18px;
          display: flex;
          flex-direction: column;
          gap: 10px;
          font-size: 13px;
        }
        .calc-row {
          display: flex;
          justify-content: space-between;
          color: #475569;
        }
        .calc-row.grand-total {
          border-top: 2px solid #cbd5e1;
          padding-top: 10px;
          font-size: 16px;
          font-weight: 800;
          color: #0f172a;
        }
        .calc-row.grand-total .val {
          color: #4f46e5;
          font-family: 'JetBrains Mono', monospace;
          font-size: 18px;
        }

        .amount-in-words-box {
          background: #ffffff;
          border: 1px solid #e2e8f0;
          border-radius: 8px;
          padding: 8px 12px;
          font-size: 12px;
          color: #334155;
          font-style: italic;
          margin-top: 4px;
        }

        /* Invoice Footer Note */
        .invoice-footer {
          margin-top: 36px;
          padding-top: 20px;
          border-top: 1px solid #e2e8f0;
          text-align: center;
          font-size: 11px;
          color: #94a3b8;
          line-height: 1.6;
          position: relative;
          z-index: 2;
        }

        /* Print Media Styles */
        @media print {
          body {
            background: #ffffff;
            padding: 0;
            margin: 0;
          }
          .action-bar {
            display: none !important;
          }
          .invoice-sheet {
            border: none;
            box-shadow: none;
            padding: 0;
            max-width: 100%;
          }
          @page {
            size: A4;
            margin: 15mm;
          }
        }
      </style>
    </head>
    <body>

      <!-- Top Interactive Controls (Hidden during print) -->
      <div class="action-bar">
        <div>
          <span style="font-size: 14px; font-weight: 800; color: #0f172a;">📄 Provider Tax Invoice</span>
          <span style="font-size: 12px; color: var(--text-muted); margin-left: 8px;">Order #<?= htmlspecialchars($order['order_number']) ?></span>
        </div>
        <div class="action-btns">
          <a href="/test-buyer" class="btn btn-back">← Back to Hub</a>
          <button type="button" class="btn btn-download" onclick="downloadInvoiceHtml()">💾 Save HTML</button>
          <button type="button" class="btn btn-print" onclick="window.print()">🖨️ Download as PDF / Print</button>
        </div>
      </div>

      <!-- Printable Invoice Sheet -->
      <div class="invoice-sheet" id="invoice-printable-sheet">
        <div class="watermark"><?= $badgeText ?></div>

        <!-- Header -->
        <div class="invoice-header">
          <div>
            <div class="provider-brand">
              <div class="provider-logo-icon">🏢</div>
              <div>
                <div class="provider-name"><?= htmlspecialchars($provider['shop_name']) ?></div>
                <div style="font-size: 12px; font-weight: 700; color: #4f46e5;">Official Marketplace Provider</div>
              </div>
            </div>
            <div class="provider-address">
              <?= htmlspecialchars($provider['address']) ?><br>
              <?= !empty($provider['landmark']) ? htmlspecialchars($provider['landmark']) . ', ' : '' ?>
              <?= htmlspecialchars($provider['state']) ?>, <?= htmlspecialchars($provider['country']) ?><br>
              Phone: <?= htmlspecialchars($provider['phone']) ?> | Email: <?= htmlspecialchars($provider['email']) ?>
            </div>
            <div class="tax-reg-info">
              GSTIN: <strong><?= htmlspecialchars($provider['gstin']) ?></strong> | PAN: <strong><?= htmlspecialchars($provider['pan']) ?></strong><br>
              Server Portal: <strong><?= htmlspecialchars($serverUrl) ?></strong>
            </div>
          </div>

          <div class="invoice-meta">
            <div class="invoice-badge-title">TAX INVOICE</div>
            <div class="invoice-num"><?= htmlspecialchars($invoiceNumber) ?></div>
            <div class="invoice-dates">
              <div>Invoice Date: <strong><?= $invoiceDate ?></strong></div>
              <div>Order Ref: <strong>#<?= htmlspecialchars($order['order_number']) ?></strong></div>
              <div>Payment Mode: <strong style="text-transform: uppercase;"><?= htmlspecialchars($order['payment_method']) ?></strong></div>
            </div>
            <div>
              <span class="status-stamp <?= $badgeClass ?>"><?= $badgeText ?></span>
            </div>
          </div>
        </div>

        <!-- Billing & Shipping Parties Grid -->
        <div class="parties-grid">
          <div>
            <div class="party-col-title">Sold & Fulfilled By (Provider)</div>
            <div class="party-name"><?= htmlspecialchars($provider['shop_name']) ?></div>
            <div class="party-details">
              <?= htmlspecialchars($provider['address']) ?><br>
              <?= htmlspecialchars($provider['state']) ?>, <?= htmlspecialchars($provider['country']) ?><br>
              Phone: <?= htmlspecialchars($provider['phone']) ?>
            </div>
          </div>

          <div>
            <div class="party-col-title">Billed & Delivered To (Buyer)</div>
            <div class="party-name"><?= htmlspecialchars($order['buyer_name']) ?></div>
            <div class="party-details">
              <?= htmlspecialchars($order['shipping_address']) ?><br>
              <?= !empty($order['landmark']) ? 'Landmark: ' . htmlspecialchars($order['landmark']) . '<br>' : '' ?>
              <?= !empty($order['city']) ? htmlspecialchars($order['city']) . '<br>' : '' ?>
              Phone: <?= htmlspecialchars($order['buyer_phone']) ?>
            </div>
          </div>
        </div>

        <!-- Line Items Table -->
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th style="width: 40px;" class="text-center">#</th>
                <th>Product Description</th>
                <th class="text-center">HSN</th>
                <th class="text-right">Unit Price</th>
                <th class="text-center">Qty</th>
                <th class="text-right">Taxable Amount</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $idx => $item): ?>
                <tr>
                  <td class="text-center font-mono" style="color: var(--text-muted);"><?= $idx + 1 ?></td>
                  <td>
                    <div class="item-title"><?= htmlspecialchars($item['product_name']) ?></div>
                    <div class="item-sku">SKU: PROD-<?= htmlspecialchars($item['product_id'] ?? ($idx + 1)) ?></div>
                  </td>
                  <td class="text-center font-mono" style="font-size: 12px; color: var(--text-muted);">851830</td>
                  <td class="text-right price-mono">₹<?= number_format((float)$item['price'], 2) ?></td>
                  <td class="text-center price-mono" style="font-weight: 700;"><?= (int)$item['quantity'] ?></td>
                  <td class="text-right price-mono" style="font-weight: 700;">₹<?= number_format((float)$item['subtotal'], 2) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- Bottom Summary & Totals -->
        <div class="bottom-section">
          <div class="notes-and-terms">
            <div class="terms-title">Terms & Conditions:</div>
            <ul style="padding-left: 18px; margin-bottom: 12px;">
              <li>Goods once sold can be returned or replaced within 7 business days per marketplace policy.</li>
              <li>Warranty is provided directly by the respective manufacturer / provider.</li>
              <li>All disputes are subject to provider jurisdiction.</li>
            </ul>

            <div class="signature-box">
              <div class="signature-seal">
                VERIFIED<br>PROVIDER<br>SEAL
              </div>
              <div style="font-size: 11px;">
                <strong><?= htmlspecialchars($provider['shop_name']) ?></strong><br>
                Authorized Digital Signature<br>
                <span style="color: var(--text-muted);">Digitally validated on <?= $invoiceDate ?></span>
              </div>
            </div>
          </div>

          <div>
            <div class="summary-card">
              <div class="calc-row">
                <span>Items Subtotal:</span>
                <span class="price-mono">₹<?= number_format($subtotal, 2) ?></span>
              </div>
              <div class="calc-row">
                <span>Shipping & Handling:</span>
                <span class="price-mono"><?= $deliveryFee === 0.0 ? 'FREE' : '₹' . number_format($deliveryFee, 2) ?></span>
              </div>
              <?php if ($discount > 0): ?>
                <div class="calc-row" style="color: #059669;">
                  <span>Special Discount:</span>
                  <span class="price-mono">-₹<?= number_format($discount, 2) ?></span>
                </div>
              <?php endif; ?>
              <div class="calc-row">
                <span>Included Taxes (GST 18%):</span>
                <span class="price-mono">₹<?= number_format(($subtotal * 0.18) / 1.18, 2) ?></span>
              </div>
              <div class="calc-row grand-total">
                <span>Total Amount:</span>
                <span class="val">₹<?= number_format($totalAmount, 2) ?></span>
              </div>

              <div class="amount-in-words-box">
                <strong>In Words:</strong> <?= htmlspecialchars($amountWords) ?>
              </div>
            </div>
          </div>
        </div>

        <!-- Footer -->
        <div class="invoice-footer">
          This is an electronically generated tax invoice issued by <strong><?= htmlspecialchars($provider['shop_name']) ?></strong>.<br>
          Verification: <strong><?= htmlspecialchars($verificationUrl) ?></strong> | Server Portal: <strong><?= htmlspecialchars($serverUrl) ?></strong><br>
          For billing support or tax queries, contact <?= htmlspecialchars($provider['email']) ?> or call <?= htmlspecialchars($provider['phone']) ?>.
        </div>
      </div>

      <script>
        function downloadInvoiceHtml() {
          const content = document.documentElement.outerHTML;
          const blob = new Blob([content], { type: 'text/html;charset=utf-8' });
          const url = URL.createObjectURL(blob);
          const a = document.createElement('a');
          a.href = url;
          a.download = 'Invoice-<?= htmlspecialchars($order['order_number']) ?>.html';
          document.body.appendChild(a);
          a.click();
          document.body.removeChild(a);
          URL.revokeObjectURL(url);
        }
      </script>
    </body>
    </html>
    <?php
}
