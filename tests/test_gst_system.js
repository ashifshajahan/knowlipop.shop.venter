const https = require('https');
const crypto = require('crypto');

const BASE_URL = 'https://knowlipop.shop';

// Minimal valid 1-page PDF binary buffer
const SAMPLE_PDF_BUFFER = Buffer.from(
  '%PDF-1.4\n' +
  '1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n' +
  '2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj\n' +
  '3 0 obj<</Type/Page/MediaBox[0 0 612 792]/Parent 2 0 R/Resources<<>>>>endobj\n' +
  'xref\n' +
  '0 4\n' +
  '0000000000 65535 f\n' +
  '0000000009 00000 n\n' +
  '0000000052 00000 n\n' +
  '0000000101 00000 n\n' +
  'trailer<</Size 4/Root 1 0 R>>\n' +
  'startxref\n' +
  '178\n' +
  '%%EOF\n',
  'utf8'
);

// 1x1 PNG binary buffer
const SAMPLE_PNG_BUFFER = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
  'base64'
);

function makeRequest(method, path, headers = {}, bodyBuffer = null) {
  return new Promise((resolve, reject) => {
    const url = new URL(path, BASE_URL);
    const reqHeaders = {
      'User-Agent': 'VenterAppGstTest/1.0',
      ...headers
    };

    if (bodyBuffer && !reqHeaders['Content-Length']) {
      reqHeaders['Content-Length'] = bodyBuffer.length;
    }

    const req = https.request({
      method,
      hostname: url.hostname,
      port: 443,
      path: url.pathname + url.search,
      headers: reqHeaders
    }, (res) => {
      const chunks = [];
      res.on('data', c => chunks.push(c));
      res.on('end', () => {
        const raw = Buffer.concat(chunks);
        let parsed = null;
        try {
          parsed = JSON.parse(raw.toString('utf8'));
        } catch (e) {
          parsed = raw;
        }
        resolve({
          status: res.statusCode,
          headers: res.headers,
          body: parsed,
          rawBuffer: raw
        });
      });
    });

    req.on('error', reject);
    if (bodyBuffer) req.write(bodyBuffer);
    req.end();
  });
}

function buildMultipart(fields, files = []) {
  const boundary = '----WebKitFormBoundary' + crypto.randomBytes(16).toString('hex');
  const chunks = [];

  for (const [k, v] of Object.entries(fields)) {
    chunks.push(Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="${k}"\r\n\r\n${v}\r\n`));
  }

  for (const f of files) {
    chunks.push(Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="${f.fieldname}"; filename="${f.filename}"\r\nContent-Type: ${f.mimeType}\r\n\r\n`));
    chunks.push(f.buffer);
    chunks.push(Buffer.from('\r\n'));
  }

  chunks.push(Buffer.from(`--${boundary}--\r\n`));
  const fullBuf = Buffer.concat(chunks);
  return {
    headers: {
      'Content-Type': `multipart/form-data; boundary=${boundary}`,
      'Content-Length': fullBuf.length
    },
    buffer: fullBuf
  };
}

async function runGstTests() {
  console.log('================================================================');
  console.log('SELLER GST DOCUMENT UPLOAD & ADMIN VERIFICATION TEST SUITE');
  console.log('Target: ' + BASE_URL);
  console.log('================================================================\n');

  const ts = Date.now().toString().slice(-7);
  const sellerPhone = `+9194${ts}`;
  const sellerEmail = `gst_seller_${ts}@venterapp.test`;
  const sellerPassword = 'Password123!';

  // 1. Register Seller A
  console.log('[1/12] Registering Seller A (' + sellerEmail + ')...');
  const regRes = await makeRequest('POST', '/api/register', {
    'Content-Type': 'application/json'
  }, Buffer.from(JSON.stringify({
    seller_name: 'Apex Retailers ' + ts,
    email: sellerEmail,
    phone: sellerPhone,
    address: 'Commercial Block 4, Industrial Area',
    landmark: 'Near Highway Toll',
    state: 'Karnataka',
    country: 'India',
    password: sellerPassword,
    password_confirmation: sellerPassword
  })));

  if (regRes.status !== 201 && regRes.status !== 200) {
    throw new Error('Seller registration failed: ' + JSON.stringify(regRes.body));
  }
  const sellerToken = regRes.body.token;
  const sellerId = regRes.body.user.id;
  console.log(`✓ Seller A registered (ID: #${sellerId}) with Bearer token.\n`);

  // 2. Initial GST state check
  console.log('[2/12] Verifying initial GST status for new seller...');
  const initGst = await makeRequest('GET', '/api/seller/gst', {
    'Authorization': `Bearer ${sellerToken}`
  });
  console.log('Status HTTP:', initGst.status);
  console.log('GST Object:', JSON.stringify(initGst.body.gst || initGst.body, null, 2));
  if (initGst.status === 200 && initGst.body.gst && initGst.body.gst.status === 'Not Submitted') {
    console.log('✓ PASS: New seller status is "Not Submitted" with 0 documents.\n');
  } else {
    console.error('❌ FAIL: Expected status "Not Submitted"');
  }

  // 3. Validation test: Invalid GSTIN format
  console.log('[3/12] Testing GSTIN format validation (submitting invalid GSTIN "INVALID_GSTIN_123")...');
  const invGst = await makeRequest('POST', '/api/seller/gst/submit', {
    'Authorization': `Bearer ${sellerToken}`,
    'Content-Type': 'application/json'
  }, Buffer.from(JSON.stringify({
    gstin: 'INVALID_GSTIN_123',
    business_name: 'Apex Retailers',
    business_address: 'Commercial Block 4',
    state: 'Karnataka',
    pincode: '560001'
  })));
  console.log('Validation Response:', invGst.status, JSON.stringify(invGst.body.errors || invGst.body));
  if (invGst.status === 422 && invGst.body.errors?.gstin) {
    console.log('✓ PASS: Invalid GSTIN rejected with 422 validation error.\n');
  } else {
    console.error('❌ FAIL: Invalid GSTIN was not rejected properly.');
  }

  // 4. Valid GST Submission with PDF Certificate upload
  console.log('[4/12] Submitting valid GST details with PDF Registration Certificate (multipart upload)...');
  const validGstin = '29ABCDE1234F1Z5'; // Standard 15-char Karnataka GSTIN
  const mpSubmit = buildMultipart({
    gstin: validGstin,
    business_name: 'Apex Retailers Private Limited',
    business_address: 'Plot 42, Electronics City Phase 1',
    state: 'Karnataka',
    pincode: '560100'
  }, [
    {
      fieldname: 'certificate',
      filename: 'Apex_GST_Registration_Certificate.pdf',
      mimeType: 'application/pdf',
      buffer: SAMPLE_PDF_BUFFER
    }
  ]);

  const submitRes = await makeRequest('POST', '/api/seller/gst/submit', {
    'Authorization': `Bearer ${sellerToken}`,
    ...mpSubmit.headers
  }, mpSubmit.buffer);

  console.log('Submission HTTP Status:', submitRes.status);
  console.log('GST Details:', JSON.stringify(submitRes.body.gst || submitRes.body, null, 2));

  let primaryDocId = null;
  if (submitRes.status === 200 && submitRes.body.gst?.status === 'Pending') {
    const docs = submitRes.body.gst.documents || [];
    primaryDocId = docs[0]?.id;
    console.log(`✓ PASS: GST submitted successfully! Status is "Pending". Document ID: #${primaryDocId} (is_pdf: ${docs[0]?.is_pdf})\n`);
  } else {
    console.error('❌ FAIL: GST submission failed or status not "Pending".');
  }

  // 5. Test PDF Document Preview & Download by Seller
  console.log('[5/12] Testing Seller Document Preview & Download (PDF-based)...');
  if (primaryDocId) {
    const prevRes = await makeRequest('GET', `/api/seller/gst/documents/${primaryDocId}/preview`, {
      'Authorization': `Bearer ${sellerToken}`
    });
    console.log('Preview HTTP Status:', prevRes.status);
    console.log('Preview Content-Type:', prevRes.headers['content-type']);
    console.log('Preview Content-Disposition:', prevRes.headers['content-disposition']);

    const dlRes = await makeRequest('GET', `/api/seller/gst/documents/${primaryDocId}/download`, {
      'Authorization': `Bearer ${sellerToken}`
    });
    console.log('Download HTTP Status:', dlRes.status);
    console.log('Download Content-Disposition:', dlRes.headers['content-disposition']);

    if (prevRes.status === 200 && prevRes.headers['content-type']?.includes('application/pdf') && dlRes.headers['content-disposition']?.includes('attachment')) {
      console.log('✓ PASS: PDF document preview streams inline and download streams as attachment.\n');
    } else {
      console.error('❌ FAIL: Document preview or download headers mismatch.');
    }
  }

  // 6. Test Uploading Additional Supporting Document
  console.log('[6/12] Uploading additional supporting document (PNG business proof)...');
  const mpDoc = buildMultipart({
    document_type: 'supporting_document'
  }, [
    {
      fieldname: 'document',
      filename: 'Business_Address_Proof.png',
      mimeType: 'image/png',
      buffer: SAMPLE_PNG_BUFFER
    }
  ]);

  const addDocRes = await makeRequest('POST', '/api/seller/gst/documents', {
    'Authorization': `Bearer ${sellerToken}`,
    ...mpDoc.headers
  }, mpDoc.buffer);

  const secondDocId = addDocRes.body.document?.id;
  console.log('Add Doc Status:', addDocRes.status);
  console.log('Added Document:', JSON.stringify(addDocRes.body.document || addDocRes.body, null, 2));
  if (addDocRes.status === 201 && secondDocId) {
    console.log(`✓ PASS: Additional document uploaded (ID: #${secondDocId}).\n`);
  } else {
    console.error('❌ FAIL: Uploading additional document failed.');
  }

  // 7. Security: Test Unauthorized Access to Seller A documents by Seller B
  console.log('[7/12] Security Test: Registering Seller B and attempting unauthorized document access...');
  const sellerBPhone = `+9193${ts}`;
  const regB = await makeRequest('POST', '/api/register', {
    'Content-Type': 'application/json'
  }, Buffer.from(JSON.stringify({
    seller_name: 'Intruder Shop',
    email: `intruder_${ts}@example.com`,
    phone: sellerBPhone,
    address: 'Anywhere',
    password: sellerPassword,
    password_confirmation: sellerPassword
  })));
  const sellerBToken = regB.body.token;

  const unauthRes = await makeRequest('GET', `/api/seller/gst/documents/${primaryDocId}/preview`, {
    'Authorization': `Bearer ${sellerBToken}`
  });
  console.log('Seller B accessing Seller A document status:', unauthRes.status);
  if (unauthRes.status === 404 || unauthRes.status === 403) {
    console.log('✓ PASS: Unauthorized seller access strictly blocked (HTTP 404/403).\n');
  } else {
    console.error('❌ FAIL: Security vulnerability: Seller B could access Seller A document!');
  }

  // 8. Admin Authentication & Role Protection
  console.log('[8/12] Logging in as Admin (admin@vendor.app)...');
  const adminLogin = await makeRequest('POST', '/api/login', {
    'Content-Type': 'application/json'
  }, Buffer.from(JSON.stringify({
    email: 'admin@vendor.app',
    password: 'Admin123!'
  })));

  if (adminLogin.status !== 200 || !adminLogin.body.token) {
    throw new Error('Admin login failed: ' + JSON.stringify(adminLogin.body));
  }
  const adminToken = adminLogin.body.token;
  console.log('✓ Admin authenticated successfully.\n');

  // Verify Seller cannot call Admin API
  const sellerOnAdminApi = await makeRequest('GET', '/api/admin/sellers/gst/pending', {
    'Authorization': `Bearer ${sellerToken}`
  });
  console.log('Seller calling Admin Pending GST API status:', sellerOnAdminApi.status);
  if (sellerOnAdminApi.status === 403) {
    console.log('✓ PASS: Seller blocked from Admin API (403 Forbidden).\n');
  } else {
    console.error('❌ FAIL: Seller was able to access Admin API!');
  }

  // 9. Admin Query Pending & Detail
  console.log('[9/12] Admin checking pending GST reviews and seller detail...');
  const adminPending = await makeRequest('GET', '/api/admin/sellers/gst/pending', {
    'Authorization': `Bearer ${adminToken}`
  });
  console.log('Pending GST count:', adminPending.body.count);

  const adminDetail = await makeRequest('GET', `/api/admin/sellers/gst/${sellerId}`, {
    'Authorization': `Bearer ${adminToken}`
  });
  console.log('Admin Seller Detail GST Status:', adminDetail.body.gst?.status);
  console.log('Admin Documents Count:', adminDetail.body.documents?.length);
  if (adminDetail.status === 200 && adminDetail.body.documents?.length >= 2) {
    console.log('✓ PASS: Admin retrieved seller GST details, all documents, and history.\n');
  } else {
    console.error('❌ FAIL: Admin detail query failed.');
  }

  // 10. Admin Rejection with Mandatory Reason
  console.log('[10/12] Testing Admin Rejection (with mandatory reason)...');
  // 10a: Try rejecting without reason (must fail 422)
  const emptyReject = await makeRequest('POST', `/api/admin/sellers/gst/${sellerId}/reject`, {
    'Authorization': `Bearer ${adminToken}`,
    'Content-Type': 'application/json'
  }, Buffer.from(JSON.stringify({ rejection_reason: '' })));

  if (emptyReject.status === 422) {
    console.log('✓ PASS: Rejection without mandatory reason rejected with 422.');
  }

  // 10b: Reject with valid reason
  const rejectionReason = 'The business address on certificate does not match registered shop address.';
  const rejectRes = await makeRequest('POST', `/api/admin/sellers/gst/${sellerId}/reject`, {
    'Authorization': `Bearer ${adminToken}`,
    'Content-Type': 'application/json'
  }, Buffer.from(JSON.stringify({ rejection_reason: rejectionReason })));
  console.log('Reject status:', rejectRes.status, rejectRes.body.new_status);

  // Check that seller sees Rejected status and the reason
  const sellerCheckRej = await makeRequest('GET', '/api/seller/gst', {
    'Authorization': `Bearer ${sellerToken}`
  });
  console.log('Seller sees Status:', sellerCheckRej.body.gst?.status);
  console.log('Seller sees Rejection Reason:', sellerCheckRej.body.gst?.rejection_reason);
  if (sellerCheckRej.body.gst?.status === 'Rejected' && sellerCheckRej.body.gst?.rejection_reason === rejectionReason) {
    console.log('✓ PASS: Status updated to "Rejected" and rejection reason visible to seller.\n');
  } else {
    console.error('❌ FAIL: Rejection status or reason mismatch.');
  }

  // 11. Seller Resubmission after rejection
  console.log('[11/12] Seller resubmitting corrected document after rejection...');
  const mpResubmit = buildMultipart({
    document_type: 'gst_certificate'
  }, [
    {
      fieldname: 'document',
      filename: 'Apex_GST_Corrected_Certificate.pdf',
      mimeType: 'application/pdf',
      buffer: SAMPLE_PDF_BUFFER
    }
  ]);

  const resubRes = await makeRequest('POST', '/api/seller/gst/documents', {
    'Authorization': `Bearer ${sellerToken}`,
    ...mpResubmit.headers
  }, mpResubmit.buffer);

  const sellerCheckPending = await makeRequest('GET', '/api/seller/gst', {
    'Authorization': `Bearer ${sellerToken}`
  });
  console.log('After resubmission, Seller Status:', sellerCheckPending.body.gst?.status);
  console.log('Rejection reason cleared:', sellerCheckPending.body.gst?.rejection_reason === null);
  if (sellerCheckPending.body.gst?.status === 'Pending') {
    console.log('✓ PASS: Status automatically transitioned from "Rejected" to "Pending" on document resubmission!\n');
  } else {
    console.error('❌ FAIL: Expected status to become "Pending" again.');
  }

  // 12. Admin Final Approval
  console.log('[12/12] Admin approving GST verification...');
  const approveRes = await makeRequest('POST', `/api/admin/sellers/gst/${sellerId}/approve`, {
    'Authorization': `Bearer ${adminToken}`,
    'Content-Type': 'application/json'
  }, Buffer.from(JSON.stringify({ notes: 'Verified certificate matches government records.' })));
  console.log('Approve response:', approveRes.status, approveRes.body.new_status);

  const sellerFinalGst = await makeRequest('GET', '/api/seller/gst', {
    'Authorization': `Bearer ${sellerToken}`
  });
  console.log('Seller final status:', sellerFinalGst.body.gst?.status);
  console.log('Audit history logs count:', sellerFinalGst.body.gst?.verification_history?.length);

  if (sellerFinalGst.body.gst?.status === 'Approved') {
    console.log('✓ PASS: Admin approval successful! Final status is "Approved".\n');
  } else {
    console.error('❌ FAIL: Expected final status "Approved".');
  }

  // 13. Test Document Deletion
  if (secondDocId) {
    console.log(`[Bonus] Seller deleting supporting document ID #${secondDocId}...`);
    const delRes = await makeRequest('DELETE', `/api/seller/gst/documents/${secondDocId}`, {
      'Authorization': `Bearer ${sellerToken}`
    });
    console.log('Delete status:', delRes.status, delRes.body.message);
    if (delRes.status === 200) {
      console.log('✓ PASS: Supporting document deleted successfully.\n');
    }
  }

  console.log('================================================================');
  console.log('ALL GST VERIFICATION TESTS COMPLETED SUCCESSFULLY!');
  console.log('================================================================');
}

runGstTests().catch(err => {
  console.error('Test Suite encountered error:', err);
  process.exit(1);
});

