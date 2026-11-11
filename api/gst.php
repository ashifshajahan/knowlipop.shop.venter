<?php

/**
 * Seller GST Management & Admin Verification API
 * Pure PHP implementation with dual-engine parity for Node.js Express.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/gst.php';

/**
 * Helper to check admin role
 */
function requireAdminUser(array $user): void
{
    if (($user['role'] ?? '') !== 'admin') {
        errorResponse('Unauthorized. Admin access required.', [], 403);
    }
}

/**
 * Helper to stream secure document (inline preview or download attachment)
 */
function streamSecureDocument(array $doc, string $mode = 'preview'): void
{
    $filePath = resolveGstFilePathPhp($doc);
    if (!$filePath || !file_exists($filePath)) {
        errorResponse('Document file not found on server.', [], 404);
    }

    $ext = strtolower(pathinfo($doc['original_name'] ?: $filePath, PATHINFO_EXTENSION));
    $mimeType = ($ext === 'pdf') ? 'application/pdf' : ($doc['mime_type'] ?: 'application/octet-stream');
    $fileName = $doc['original_name'] ?: basename($filePath);
    $disposition = ($mode === 'download') ? 'attachment' : 'inline';

    // Clear any previous output buffering
    if (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . filesize($filePath));
    header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($fileName) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    readfile($filePath);
    exit;
}

// ==========================================
// SELLER ENDPOINTS
// ==========================================

/**
 * POST /api/seller/gst/submit or POST /api/seller/gst
 * Submits or updates seller GST registration details & certificate
 */
function apiSellerSubmitGst(PDO $pdo): void
{
    ensureGstTables($pdo);
    $user = authenticateUser($pdo);
    requireActiveSellerForMutationPhp($user);
    $sellerId = (int) $user['id'];

    $input = getJsonInput();
    if (empty($input) && !empty($_POST)) {
        $input = $_POST;
    }

    $rawGstin = extractFieldPhp($input, ['gstin', 'gst_number', 'gstNumber', 'gst_registration_number']);
    $rawBusinessName = extractFieldPhp($input, ['business_name', 'businessName', 'registered_business_name', 'company_name', 'shop_name']);
    $rawBusinessAddress = extractFieldPhp($input, ['business_address', 'businessAddress', 'address', 'shop_address']);
    $rawState = extractFieldPhp($input, ['state', 'province', 'region']);
    $rawPincode = extractFieldPhp($input, ['pincode', 'pin_code', 'pin', 'zip', 'postal_code']);

    // Fetch existing GST record
    $fetchStmt = $pdo->prepare("SELECT * FROM seller_gst_details WHERE seller_id = :seller_id LIMIT 1");
    $fetchStmt->execute([':seller_id' => $sellerId]);
    $existing = $fetchStmt->fetch();

    $gstin = !empty($rawGstin) ? strtoupper(trim($rawGstin)) : ($existing['gstin'] ?? '');
    $businessName = !empty($rawBusinessName) ? trim($rawBusinessName) : ($existing['business_name'] ?? $user['seller_name'] ?? 'Seller Business');
    $businessAddress = !empty($rawBusinessAddress) ? trim($rawBusinessAddress) : ($existing['business_address'] ?? $user['address'] ?? 'Address on file');
    $state = !empty($rawState) ? trim($rawState) : ($existing['state'] ?? $user['state'] ?? 'State');
    $pincode = !empty($rawPincode) ? trim($rawPincode) : ($existing['pincode'] ?? '000000');

    $prevStatus = $existing ? $existing['status'] : 'Not Submitted';
    $newStatus = 'Pending';
    $action = ($prevStatus === 'Rejected') ? 'resubmitted' : ($existing ? 'updated' : 'submitted');

    // Handle File Uploads (Certificate or Supporting Document)
    $uploadedDocs = [];
    $storageDir = getGstStorageDir();

    // Check files array in various keys: certificate, document, file, gst_certificate, documents
    $filesToProcess = [];
    $fileKeys = ['certificate', 'document', 'gst_certificate', 'file', 'gst_document', 'documents', 'pdf'];
    foreach ($fileKeys as $k) {
        if (!empty($_FILES[$k]['name'])) {
            if (is_array($_FILES[$k]['name'])) {
                for ($i = 0; $i < count($_FILES[$k]['name']); $i++) {
                    if (!empty($_FILES[$k]['name'][$i])) {
                        $filesToProcess[] = [
                            'name' => $_FILES[$k]['name'][$i],
                            'type' => $_FILES[$k]['type'][$i] ?? 'application/octet-stream',
                            'tmp_name' => $_FILES[$k]['tmp_name'][$i],
                            'error' => $_FILES[$k]['error'][$i],
                            'size' => $_FILES[$k]['size'][$i] ?? 0,
                            'type_label' => ($k === 'documents' && $i > 0) ? 'supporting_document' : 'gst_certificate'
                        ];
                    }
                }
            } else {
                $filesToProcess[] = [
                    'name' => $_FILES[$k]['name'],
                    'type' => $_FILES[$k]['type'] ?? 'application/octet-stream',
                    'tmp_name' => $_FILES[$k]['tmp_name'],
                    'error' => $_FILES[$k]['error'],
                    'size' => $_FILES[$k]['size'] ?? 0,
                    'type_label' => ($k === 'document' || $k === 'file') ? 'supporting_document' : 'gst_certificate'
                ];
            }
        }
    }

    // Files are optional on submit (can upload PDF directly or submit GST details via JSON)

    // Validate uploaded files
    foreach ($filesToProcess as $f) {
        if ($f['error'] !== UPLOAD_ERR_OK) {
            errorResponse('File upload failed with error code: ' . $f['error'], [], 422);
        }
        if ($f['size'] > 5 * 1024 * 1024) {
            errorResponse('File size exceeds the 5 MB limit. Please upload a smaller file.', [
                'document' => ['File size exceeds the 5 MB limit. Please upload a file smaller than 5 MB.']
            ], 422);
        }
        if (!isAllowedGstFile($f['name'], $f['type'])) {
            errorResponse('Invalid file format. Only PDF, JPG, and PNG documents are supported.', [
                'document' => ['Invalid file format. Only PDF, JPG, and PNG documents are supported.']
            ], 422);
        }
    }

    // Save GST record
    if ($existing) {
        $gstId = (int) $existing['id'];
        $updateStmt = $pdo->prepare("
            UPDATE seller_gst_details
            SET gstin = :gstin,
                business_name = :business_name,
                business_address = :business_address,
                state = :state,
                pincode = :pincode,
                status = :status,
                rejection_reason = NULL,
                submitted_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $updateStmt->execute([
            ':gstin' => $gstin,
            ':business_name' => $businessName,
            ':business_address' => $businessAddress,
            ':state' => $state,
            ':pincode' => $pincode,
            ':status' => $newStatus,
            ':id' => $gstId
        ]);
    } else {
        $insertStmt = $pdo->prepare("
            INSERT INTO seller_gst_details
            (seller_id, gstin, business_name, business_address, state, pincode, status, submitted_at, created_at, updated_at)
            VALUES (:seller_id, :gstin, :business_name, :business_address, :state, :pincode, :status, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $insertStmt->execute([
            ':seller_id' => $sellerId,
            ':gstin' => $gstin,
            ':business_name' => $businessName,
            ':business_address' => $businessAddress,
            ':state' => $state,
            ':pincode' => $pincode,
            ':status' => $newStatus
        ]);
        $gstId = (int) $pdo->lastInsertId();
    }

    // Store uploaded files
    foreach ($filesToProcess as $idx => $f) {
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $uniqueName = 'gst_' . $sellerId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $destPath = $storageDir . '/' . $uniqueName;

        if (move_uploaded_file($f['tmp_name'], $destPath)) {
            $isPrimary = ($f['type_label'] === 'gst_certificate') ? 1 : 0;
            // If primary, demote any previous primary certificates
            if ($isPrimary === 1) {
                $pdo->prepare("UPDATE seller_gst_documents SET is_primary = 0 WHERE seller_id = :seller_id")
                    ->execute([':seller_id' => $sellerId]);
            }

            $storedMime = ($ext === 'pdf') ? 'application/pdf' : (($ext === 'png') ? 'image/png' : (($ext === 'jpg' || $ext === 'jpeg') ? 'image/jpeg' : $f['type']));
            $docStmt = $pdo->prepare("
                INSERT INTO seller_gst_documents
                (seller_id, gst_id, document_type, original_name, file_name, file_path, file_size, mime_type, is_primary, created_at, updated_at)
                VALUES (:seller_id, :gst_id, :document_type, :original_name, :file_name, :file_path, :file_size, :mime_type, :is_primary, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");
            $docStmt->execute([
                ':seller_id' => $sellerId,
                ':gst_id' => $gstId,
                ':document_type' => $f['type_label'],
                ':original_name' => $f['name'],
                ':file_name' => $uniqueName,
                ':file_path' => $destPath,
                ':file_size' => $f['size'],
                ':mime_type' => $storedMime,
                ':is_primary' => $isPrimary
            ]);
            $docId = (int) $pdo->lastInsertId();

            $uploadedDocs[] = formatGstDocument([
                'id' => $docId,
                'seller_id' => $sellerId,
                'gst_id' => $gstId,
                'document_type' => $f['type_label'],
                'original_name' => $f['name'],
                'file_name' => $uniqueName,
                'file_size' => $f['size'],
                'mime_type' => $storedMime,
                'is_primary' => $isPrimary,
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }
    }

    // Log Action in Verification History
    $notes = ($action === 'resubmitted')
        ? 'Seller resubmitted updated GST registration details and documents.'
        : 'Seller submitted GST registration details for admin verification.';
    logGstVerification($pdo, $sellerId, $gstId, $action, $prevStatus, $newStatus, $notes, $sellerId, 'seller');

    // Fetch updated record & all docs
    $docsStmt = $pdo->prepare("SELECT * FROM seller_gst_documents WHERE seller_id = :seller_id ORDER BY is_primary DESC, id DESC");
    $docsStmt->execute([':seller_id' => $sellerId]);
    $allDocs = array_map(fn($d) => formatGstDocument($d, false), $docsStmt->fetchAll());

    $logsStmt = $pdo->prepare("SELECT * FROM seller_gst_verification_logs WHERE seller_id = :seller_id ORDER BY id DESC LIMIT 20");
    $logsStmt->execute([':seller_id' => $sellerId]);
    $allLogs = $logsStmt->fetchAll();

    jsonResponse([
        'status' => 'success',
        'message' => 'PDF document uploaded successfully. Your verification request is currently Pending review.',
        'gst_status' => $newStatus,
        'gst' => [
            'id' => $gstId,
            'seller_id' => $sellerId,
            'gstin' => $gstin,
            'business_name' => $businessName,
            'business_address' => $businessAddress,
            'state' => $state,
            'pincode' => $pincode,
            'status' => $newStatus,
            'rejection_reason' => null,
            'submitted_at' => date('Y-m-d H:i:s'),
            'verified_at' => null,
            'documents' => $allDocs,
            'verification_history' => $allLogs
        ]
    ], 200);
}

/**
 * GET /api/seller/gst or /api/seller/gst/status
 * Returns current authenticated seller's GST details, documents, and verification history
 */
function apiSellerGetGst(PDO $pdo): void
{
    ensureGstTables($pdo);
    $user = authenticateUser($pdo);
    $sellerId = (int) $user['id'];

    $fetchStmt = $pdo->prepare("SELECT * FROM seller_gst_details WHERE seller_id = :seller_id LIMIT 1");
    $fetchStmt->execute([':seller_id' => $sellerId]);
    $gst = $fetchStmt->fetch();

    if (!$gst) {
        jsonResponse([
            'status' => 'success',
            'gst_status' => 'Not Submitted',
            'message' => 'No GST document has been uploaded yet. Please upload your GST PDF document.',
            'has_submitted' => false,
            'gst' => [
                'status' => 'Not Submitted',
                'seller_id' => $sellerId,
                'gstin' => null,
                'business_name' => null,
                'business_address' => null,
                'state' => null,
                'pincode' => null,
                'rejection_reason' => null,
                'submitted_at' => null,
                'verified_at' => null,
                'documents' => [],
                'verification_history' => []
            ]
        ]);
    }

    $docsStmt = $pdo->prepare("SELECT * FROM seller_gst_documents WHERE seller_id = :seller_id ORDER BY is_primary DESC, id DESC");
    $docsStmt->execute([':seller_id' => $sellerId]);
    $docs = array_map(fn($d) => formatGstDocument($d, false), $docsStmt->fetchAll());

    $logsStmt = $pdo->prepare("SELECT * FROM seller_gst_verification_logs WHERE seller_id = :seller_id ORDER BY id DESC LIMIT 20");
    $logsStmt->execute([':seller_id' => $sellerId]);
    $logs = $logsStmt->fetchAll();

    $statusMessage = 'Your GST document is currently pending admin verification.';
    if ($gst['status'] === 'Approved') {
        $statusMessage = 'Your GST document has been verified and Approved.';
    } elseif ($gst['status'] === 'Rejected') {
        $statusMessage = 'Your GST verification was Rejected: ' . ($gst['rejection_reason'] ?: 'Document does not meet requirements') . '. Please upload a corrected PDF document.';
    }

    jsonResponse([
        'status' => 'success',
        'gst_status' => $gst['status'],
        'message' => $statusMessage,
        'has_submitted' => true,
        'gst' => [
            'id' => (int) $gst['id'],
            'seller_id' => $sellerId,
            'gstin' => $gst['gstin'],
            'business_name' => $gst['business_name'],
            'business_address' => $gst['business_address'],
            'state' => $gst['state'],
            'pincode' => $gst['pincode'],
            'status' => $gst['status'],
            'rejection_reason' => $gst['rejection_reason'],
            'submitted_at' => $gst['submitted_at'],
            'verified_at' => $gst['verified_at'],
            'documents' => $docs,
            'verification_history' => $logs
        ]
    ]);
}

/**
 * POST /api/seller/gst/documents
 * Allows seller to upload a new or replacement document (PDF / JPG / PNG)
 */
function apiSellerUploadGstDocument(PDO $pdo): void
{
    ensureGstTables($pdo);
    $user = authenticateUser($pdo);
    requireActiveSellerForMutationPhp($user);
    $sellerId = (int) $user['id'];

    $fileField = null;
    $possibleKeys = ['document', 'certificate', 'file', 'gst_certificate', 'supporting_document'];
    foreach ($possibleKeys as $k) {
        if (!empty($_FILES[$k]['name'])) {
            $fileField = $_FILES[$k];
            break;
        }
    }

    if (!$fileField) {
        errorResponse('No document file was uploaded.', ['document' => ['Please select a document file to upload (PDF, JPG, or PNG).']], 422);
    }

    if ($fileField['error'] !== UPLOAD_ERR_OK) {
        errorResponse('File upload failed with error code: ' . $fileField['error'], [], 422);
    }

    if ($fileField['size'] > 5 * 1024 * 1024) {
        errorResponse('File size exceeds the 5 MB limit. Please upload a file smaller than 5 MB.', [
            'document' => ['File size exceeds the 5 MB limit. Please upload a file smaller than 5 MB.']
        ], 422);
    }

    if (!isAllowedGstFile($fileField['name'], $fileField['type'])) {
        errorResponse('Invalid file format. Only PDF, JPG, and PNG documents are supported.', [
            'document' => ['Invalid file format. Only PDF, JPG, and PNG documents are supported.']
        ], 422);
    }

    $docType = trim($_POST['document_type'] ?? 'supporting_document');
    if (!in_array($docType, ['gst_certificate', 'supporting_document', 'pan_card', 'business_proof'], true)) {
        $docType = 'supporting_document';
    }

    // Get or create GST details record
    $fetchStmt = $pdo->prepare("SELECT * FROM seller_gst_details WHERE seller_id = :seller_id LIMIT 1");
    $fetchStmt->execute([':seller_id' => $sellerId]);
    $gst = $fetchStmt->fetch();

    $gstId = $gst ? (int) $gst['id'] : null;
    $prevStatus = $gst ? $gst['status'] : 'Not Submitted';

    $storageDir = getGstStorageDir();
    $ext = strtolower(pathinfo($fileField['name'], PATHINFO_EXTENSION));
    $uniqueName = 'gst_' . $sellerId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destPath = $storageDir . '/' . $uniqueName;

    if (!move_uploaded_file($fileField['tmp_name'], $destPath)) {
        errorResponse('Failed to move uploaded document to secure storage.', [], 500);
    }

    $isPrimary = ($docType === 'gst_certificate') ? 1 : 0;
    if ($isPrimary === 1) {
        $pdo->prepare("UPDATE seller_gst_documents SET is_primary = 0 WHERE seller_id = :seller_id")
            ->execute([':seller_id' => $sellerId]);
    }

    $storedMime = ($ext === 'pdf') ? 'application/pdf' : (($ext === 'png') ? 'image/png' : (($ext === 'jpg' || $ext === 'jpeg') ? 'image/jpeg' : $fileField['type']));
    $insDoc = $pdo->prepare("
        INSERT INTO seller_gst_documents
        (seller_id, gst_id, document_type, original_name, file_name, file_path, file_size, mime_type, is_primary, created_at, updated_at)
        VALUES (:seller_id, :gst_id, :document_type, :original_name, :file_name, :file_path, :file_size, :mime_type, :is_primary, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    ");
    $insDoc->execute([
        ':seller_id' => $sellerId,
        ':gst_id' => $gstId,
        ':document_type' => $docType,
        ':original_name' => $fileField['name'],
        ':file_name' => $uniqueName,
        ':file_path' => $destPath,
        ':file_size' => $fileField['size'],
        ':mime_type' => $storedMime,
        ':is_primary' => $isPrimary
    ]);
    $docId = (int) $pdo->lastInsertId();

    // If seller's GST was previously Rejected, reset to Pending on new document submission
    if ($gst && $prevStatus === 'Rejected') {
        $pdo->prepare("UPDATE seller_gst_details SET status = 'Pending', rejection_reason = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
            ->execute([':id' => $gstId]);
        logGstVerification($pdo, $sellerId, $gstId, 'resubmitted', 'Rejected', 'Pending', 'Seller uploaded new document after rejection.', $sellerId, 'seller');
    } else {
        logGstVerification($pdo, $sellerId, $gstId, 'document_uploaded', $prevStatus, $prevStatus, 'Uploaded document: ' . $fileField['name'], $sellerId, 'seller');
    }

    jsonResponse([
        'status' => 'success',
        'message' => 'Document uploaded successfully.',
        'document' => formatGstDocument([
            'id' => $docId,
            'seller_id' => $sellerId,
            'gst_id' => $gstId,
            'document_type' => $docType,
            'original_name' => $fileField['name'],
            'file_name' => $uniqueName,
            'file_size' => $fileField['size'],
            'mime_type' => $storedMime,
            'is_primary' => $isPrimary,
            'created_at' => date('Y-m-d H:i:s')
        ], false)
    ], 201);
}

/**
 * DELETE /api/seller/gst/documents/{id}
 * Seller deletes a document they uploaded
 */
function apiSellerDeleteGstDocument(PDO $pdo, int $docId): void
{
    ensureGstTables($pdo);
    $user = authenticateUser($pdo);
    requireActiveSellerForMutationPhp($user);
    $sellerId = (int) $user['id'];

    $stmt = $pdo->prepare("SELECT * FROM seller_gst_documents WHERE id = :id AND seller_id = :seller_id LIMIT 1");
    $stmt->execute([':id' => $docId, ':seller_id' => $sellerId]);
    $doc = $stmt->fetch();

    if (!$doc) {
        errorResponse('Document not found or you do not have permission to delete it.', [], 404);
    }

    // Delete physical file
    $resolvedPath = resolveGstFilePathPhp($doc);
    if ($resolvedPath && file_exists($resolvedPath)) {
        @unlink($resolvedPath);
    }

    // Delete database record
    $pdo->prepare("DELETE FROM seller_gst_documents WHERE id = :id")->execute([':id' => $docId]);

    logGstVerification($pdo, $sellerId, $doc['gst_id'] ? (int)$doc['gst_id'] : null, 'document_deleted', null, 'Pending', 'Deleted document: ' . $doc['original_name'], $sellerId, 'seller');

    jsonResponse([
        'status' => 'success',
        'message' => 'Document deleted successfully.'
    ]);
}

/**
 * GET /api/seller/gst/documents/{id}/preview or /download
 * Seller previews or downloads their own document
 */
function apiSellerViewGstDocument(PDO $pdo, int $docId, string $mode = 'preview'): void
{
    ensureGstTables($pdo);
    $user = authenticateUser($pdo);
    $sellerId = (int) $user['id'];

    $stmt = $pdo->prepare("SELECT * FROM seller_gst_documents WHERE id = :id AND seller_id = :seller_id LIMIT 1");
    $stmt->execute([':id' => $docId, ':seller_id' => $sellerId]);
    $doc = $stmt->fetch();

    if (!$doc) {
        errorResponse('Document not found or access denied.', [], 404);
    }

    streamSecureDocument($doc, $mode);
}

// ==========================================
// ADMIN ENDPOINTS
// ==========================================

/**
 * GET /api/admin/sellers/gst/pending
 * Returns list of all sellers with pending GST verification
 */
function apiAdminGetPendingGst(PDO $pdo): void
{
    ensureGstTables($pdo);
    $user = authenticateUser($pdo);
    requireAdminUser($user);

    $stmt = $pdo->query("
        SELECT g.*, u.seller_name, u.name as user_name, u.phone, u.email,
               (SELECT COUNT(*) FROM seller_gst_documents d WHERE d.seller_id = g.seller_id) as documents_count
        FROM seller_gst_details g
        JOIN users u ON g.seller_id = u.id
        WHERE g.status = 'Pending' OR g.status = 'Resubmitted'
        ORDER BY g.updated_at DESC
    ");
    $rows = $stmt->fetchAll();

    jsonResponse([
        'status' => 'success',
        'count' => count($rows),
        'pending_sellers' => $rows
    ]);
}

/**
 * GET /api/admin/sellers/gst
 * Returns all sellers with GST details (supports ?status=all|pending|approved|rejected & search)
 */
function apiAdminGetSellersGst(PDO $pdo): void
{
    ensureGstTables($pdo);
    $user = authenticateUser($pdo);
    requireAdminUser($user);

    $statusFilter = strtolower(trim($_GET['status'] ?? 'all'));
    $search = trim($_GET['search'] ?? '');
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int) ($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $whereClauses = [];
    $params = [];

    if ($statusFilter !== 'all' && $statusFilter !== '') {
        $whereClauses[] = "LOWER(g.status) = :status";
        $params[':status'] = $statusFilter;
    }

    if ($search !== '') {
        $whereClauses[] = "(g.gstin LIKE :s OR g.business_name LIKE :s OR u.seller_name LIKE :s OR u.phone LIKE :s OR u.email LIKE :s)";
        $params[':s'] = "%{$search}%";
    }

    $whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

    // Total counts summary
    $countsStmt = $pdo->query("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN status = 'Pending' OR status = 'Resubmitted' THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved_count,
            SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected_count
        FROM seller_gst_details
    ");
    $metrics = $countsStmt->fetch();

    // Query records
    $stmt = $pdo->prepare("
        SELECT g.*, u.seller_name, u.name as user_name, u.phone, u.email,
               (SELECT COUNT(*) FROM seller_gst_documents d WHERE d.seller_id = g.seller_id) as documents_count
        FROM seller_gst_details g
        JOIN users u ON g.seller_id = u.id
        {$whereSql}
        ORDER BY g.updated_at DESC
        LIMIT :limit OFFSET :offset
    ");
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    jsonResponse([
        'status' => 'success',
        'metrics' => [
            'total' => (int) ($metrics['total'] ?? 0),
            'pending' => (int) ($metrics['pending_count'] ?? 0),
            'approved' => (int) ($metrics['approved_count'] ?? 0),
            'rejected' => (int) ($metrics['rejected_count'] ?? 0),
        ],
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'count' => count($rows)
        ],
        'sellers' => $rows
    ]);
}

/**
 * GET /api/admin/sellers/gst/{sellerId}
 * Returns specific seller's GST details, all documents, and verification audit trail
 */
function apiAdminGetSellerGstDetail(PDO $pdo, int $sellerId): void
{
    ensureGstTables($pdo);
    $user = authenticateUser($pdo);
    requireAdminUser($user);

    // Fetch seller info
    $sellerStmt = $pdo->prepare("SELECT id, name, seller_name, phone, email, address, state, country, created_at FROM users WHERE id = :id LIMIT 1");
    $sellerStmt->execute([':id' => $sellerId]);
    $seller = $sellerStmt->fetch();

    if (!$seller) {
        errorResponse('Seller not found.', [], 404);
    }

    $gstStmt = $pdo->prepare("SELECT * FROM seller_gst_details WHERE seller_id = :seller_id LIMIT 1");
    $gstStmt->execute([':seller_id' => $sellerId]);
    $gst = $gstStmt->fetch();

    $docsStmt = $pdo->prepare("SELECT * FROM seller_gst_documents WHERE seller_id = :seller_id ORDER BY is_primary DESC, id DESC");
    $docsStmt->execute([':seller_id' => $sellerId]);
    $docs = array_map(fn($d) => formatGstDocument($d, true), $docsStmt->fetchAll());

    $logsStmt = $pdo->prepare("SELECT * FROM seller_gst_verification_logs WHERE seller_id = :seller_id ORDER BY id DESC");
    $logsStmt->execute([':seller_id' => $sellerId]);
    $logs = $logsStmt->fetchAll();

    jsonResponse([
        'status' => 'success',
        'seller' => safeSeller($seller),
        'gst' => $gst ?: [
            'status' => 'Not Submitted',
            'seller_id' => $sellerId,
            'gstin' => null,
            'business_name' => null,
            'business_address' => null,
            'state' => null,
            'pincode' => null,
            'rejection_reason' => null
        ],
        'documents' => $docs,
        'verification_history' => $logs
    ]);
}

/**
 * POST /api/admin/sellers/gst/{sellerId}/approve
 * Approves a seller's GST document and details
 */
function apiAdminApproveGst(PDO $pdo, int $sellerId): void
{
    ensureGstTables($pdo);
    $user = authenticateUser($pdo);
    requireAdminUser($user);
    $adminId = (int) $user['id'];

    $gstStmt = $pdo->prepare("SELECT * FROM seller_gst_details WHERE seller_id = :seller_id LIMIT 1");
    $gstStmt->execute([':seller_id' => $sellerId]);
    $gst = $gstStmt->fetch();

    if (!$gst) {
        errorResponse('No GST record found for this seller.', [], 404);
    }

    $prevStatus = $gst['status'];
    $input = getJsonInput();
    $notes = trim(extractFieldPhp($input, ['notes', 'comment', 'message'], 'Approved by administrator'));

    $updateStmt = $pdo->prepare("
        UPDATE seller_gst_details
        SET status = 'Approved',
            rejection_reason = NULL,
            verified_by = :admin_id,
            verified_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ");
    $updateStmt->execute([
        ':admin_id' => $adminId,
        ':id' => $gst['id']
    ]);

    logGstVerification($pdo, $sellerId, (int)$gst['id'], 'approved', $prevStatus, 'Approved', $notes, $adminId, 'admin');

    jsonResponse([
        'status' => 'success',
        'message' => 'Seller GST has been approved successfully.',
        'gst_id' => (int) $gst['id'],
        'seller_id' => $sellerId,
        'new_status' => 'Approved',
        'verified_at' => date('Y-m-d H:i:s'),
        'verified_by' => $adminId
    ]);
}

/**
 * POST /api/admin/sellers/gst/{sellerId}/reject
 * Rejects a seller's GST with a mandatory rejection reason
 */
function apiAdminRejectGst(PDO $pdo, int $sellerId): void
{
    ensureGstTables($pdo);
    $user = authenticateUser($pdo);
    requireAdminUser($user);
    $adminId = (int) $user['id'];

    $input = getJsonInput();
    if (empty($input) && !empty($_POST)) {
        $input = $_POST;
    }

    $reason = trim(extractFieldPhp($input, ['rejection_reason', 'reason', 'notes', 'comment', 'message']));
    if (empty($reason)) {
        errorResponse('A rejection reason is mandatory when rejecting GST documents.', [
            'rejection_reason' => ['The rejection reason field is required.']
        ], 422);
    }

    $gstStmt = $pdo->prepare("SELECT * FROM seller_gst_details WHERE seller_id = :seller_id LIMIT 1");
    $gstStmt->execute([':seller_id' => $sellerId]);
    $gst = $gstStmt->fetch();

    if (!$gst) {
        errorResponse('No GST record found for this seller.', [], 404);
    }

    $prevStatus = $gst['status'];

    $updateStmt = $pdo->prepare("
        UPDATE seller_gst_details
        SET status = 'Rejected',
            rejection_reason = :reason,
            verified_by = :admin_id,
            verified_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ");
    $updateStmt->execute([
        ':reason' => $reason,
        ':admin_id' => $adminId,
        ':id' => $gst['id']
    ]);

    logGstVerification($pdo, $sellerId, (int)$gst['id'], 'rejected', $prevStatus, 'Rejected', $reason, $adminId, 'admin');

    jsonResponse([
        'status' => 'success',
        'message' => 'Seller GST has been rejected.',
        'gst_id' => (int) $gst['id'],
        'seller_id' => $sellerId,
        'new_status' => 'Rejected',
        'rejection_reason' => $reason,
        'verified_at' => date('Y-m-d H:i:s'),
        'verified_by' => $adminId
    ]);
}

/**
 * GET /api/admin/sellers/gst/documents/{docId}/preview or /download
 * Admin previews or downloads any seller's GST document
 */
function apiAdminViewGstDocument(PDO $pdo, int $docId, string $mode = 'preview'): void
{
    ensureGstTables($pdo);
    $user = authenticateUser($pdo);
    requireAdminUser($user);

    $stmt = $pdo->prepare("SELECT * FROM seller_gst_documents WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $docId]);
    $doc = $stmt->fetch();

    if (!$doc) {
        errorResponse('Document not found.', [], 404);
    }

    streamSecureDocument($doc, $mode);
}

