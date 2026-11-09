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

function makeRequest(method, path, headers = {}, bodyBuffer = null) {
  return new Promise((resolve, reject) => {
    const url = new URL(path, BASE_URL);
    const reqHeaders = {
      'User-Agent': 'VenterAppPdfOnlyTest/1.0',
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
          parsed = raw.toString('utf8');
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

async function runPdfOnlyGstTest() {
  console.log('================================================================');
  console.log('PDF-ONLY GST DOCUMENT UPLOAD & STATUS VERIFICATION TEST');
  console.log('Target: ' + BASE_URL);
  console.log('================================================================\n');

  const ts = Date.now().toString().slice(-7);
  const sellerEmail = `pdf_seller_${ts}@venterapp.test`;
  const sellerPassword = 'Password123!';

  // 1. Register Seller
  console.log('[1/8] Registering Seller (' + sellerEmail + ')...');
  const regRes = await makeRequest('POST', '/api/register', {
    'Content-Type': 'application/json'
  }, Buffer.from(JSON.stringify({
    seller_name: 'Quick PDF Seller ' + ts,
    email: sellerEmail,
    phone: `+9195${ts}`,
    address: 'Electronics Zone Building A',
    state: 'Karnataka',
    country: 'India',
    password: sellerPassword,
    password_confirmation: sellerPassword
  })));

  if (regRes.status !== 201 && regRes.status !== 200) {
    throw new Error('Registration failed: ' + JSON.stringify(regRes.body));
  }
  const sellerToken = regRes.body.token;
  const sellerId = regRes.body.user.id;
  console.log(`✓ Seller registered: ID #${sellerId}\n`);

  // 2. Check initial status
  console.log('[2/8] Checking initial status (GET /api/seller/gst)...');
  const status1 = await makeRequest('GET', '/api/seller/gst', {
    'Authorization': `Bearer ${sellerToken}`
  });
  console.log('HTTP Status:', status1.status);
  console.log('Response body:', JSON.stringify(status1.body, null, 2));
  const currentStatus1 = status1.body.gst_status || status1.body.gst?.status;
  if (status1.status === 200 && currentStatus1 === 'Not Submitted') {
    console.log('✓ PASS: Initial status is "Not Submitted"\n');
  } else {
    throw new Error('Expected initial status "Not Submitted"');
  }

  // 3. Upload PDF ONLY (ZERO form fields)
  console.log('[3/8] Uploading PDF document ONLY with ZERO form fields...');
  const mpOnlyPdf = buildMultipart({}, [
    {
      fieldname: 'document',
      filename: 'Seller_GST_Certificate.pdf',
      mimeType: 'application/pdf',
      buffer: SAMPLE_PDF_BUFFER
    }
  ]);

  // Try /api/seller/gst/upload or fallback to /api/seller/gst/documents
  let uploadRes = await makeRequest('POST', '/api/seller/gst/upload', {
    'Authorization': `Bearer ${sellerToken}`,
    ...mpOnlyPdf.headers
  }, mpOnlyPdf.buffer);

  if (uploadRes.status === 404) {
    uploadRes = await makeRequest('POST', '/api/seller/gst/documents', {
      'Authorization': `Bearer ${sellerToken}`,
      ...mpOnlyPdf.headers
    }, mpOnlyPdf.buffer);
  }

  console.log('HTTP Status:', uploadRes.status);
  console.log('Response body:', JSON.stringify(uploadRes.body, null, 2));
  if (uploadRes.status === 200 || uploadRes.status === 201) {
    console.log('✓ PASS: PDF uploaded successfully without form fields!\n');
  } else {
    throw new Error('PDF upload failed: ' + JSON.stringify(uploadRes.body));
  }

  // 4. Verify user can see current status
  console.log('[4/8] User checking current status (GET /api/seller/gst)...');
  const status2 = await makeRequest('GET', '/api/seller/gst', {
    'Authorization': `Bearer ${sellerToken}`
  });
  console.log('HTTP Status:', status2.status);
  const curStatus2 = status2.body.gst_status || status2.body.gst?.status;
  console.log('Current status:', curStatus2);
  console.log('Documents attached:', status2.body.gst?.documents?.length);
  if ((curStatus2 === 'Pending' || curStatus2 === 'Not Submitted') && status2.body.gst?.documents?.length >= 1) {
    console.log('✓ PASS: User can clearly see current status and attached documents!\n');
  } else {
    throw new Error('Status verification failed');
  }

  // 5. Admin Login & Check
  console.log('[5/8] Admin logging in to inspect submission...');
  const adminLogin = await makeRequest('POST', '/api/login', {
    'Content-Type': 'application/json'
  }, Buffer.from(JSON.stringify({
    email: 'admin@vendor.app',
    password: 'Admin123!'
  })));
  const adminToken = adminLogin.body.token;

  const adminSellerView = await makeRequest('GET', `/api/admin/sellers/gst/${sellerId}`, {
    'Authorization': `Bearer ${adminToken}`
  });
  console.log('Admin saw seller GST status:', adminSellerView.body.gst?.status);
  console.log('Admin saw documents count:', adminSellerView.body.documents?.length);

  // 6. Admin Rejection with reason
  console.log('\n[6/8] Admin rejecting request with reason...');
  const rejReason = 'Uploaded PDF has blurred text. Please upload a clear original copy.';
  const rejRes = await makeRequest('POST', `/api/admin/sellers/gst/${sellerId}/reject`, {
    'Authorization': `Bearer ${adminToken}`,
    'Content-Type': 'application/json'
  }, Buffer.from(JSON.stringify({ rejection_reason: rejReason })));
  console.log('Reject HTTP:', rejRes.status, 'New status:', rejRes.body.new_status);

  // Verify Seller sees Rejected status and reason
  const status3 = await makeRequest('GET', '/api/seller/gst', {
    'Authorization': `Bearer ${sellerToken}`
  });
  const curStatus3 = status3.body.gst_status || status3.body.gst?.status;
  console.log('Seller status after rejection:', curStatus3);
  console.log('Seller sees rejection reason:', status3.body.gst?.rejection_reason);
  if (curStatus3 === 'Rejected' && status3.body.gst?.rejection_reason === rejReason) {
    console.log('✓ PASS: Seller clearly sees status is "Rejected" along with admin reason!\n');
  } else {
    throw new Error('Seller did not see rejection reason');
  }

  // 7. Seller re-uploads PDF (zero fields) to resubmit
  console.log('[7/8] Seller re-uploading corrected PDF document (ZERO form fields)...');
  const mpResubmit = buildMultipart({}, [
    {
      fieldname: 'document',
      filename: 'Clear_Official_GST_Certificate.pdf',
      mimeType: 'application/pdf',
      buffer: SAMPLE_PDF_BUFFER
    }
  ]);
  let resubRes = await makeRequest('POST', '/api/seller/gst/upload', {
    'Authorization': `Bearer ${sellerToken}`,
    ...mpResubmit.headers
  }, mpResubmit.buffer);

  if (resubRes.status === 404) {
    resubRes = await makeRequest('POST', '/api/seller/gst/documents', {
      'Authorization': `Bearer ${sellerToken}`,
      ...mpResubmit.headers
    }, mpResubmit.buffer);
  }

  const status4 = await makeRequest('GET', '/api/seller/gst', {
    'Authorization': `Bearer ${sellerToken}`
  });
  const curStatus4 = status4.body.gst_status || status4.body.gst?.status;
  console.log('Status after resubmitting PDF:', curStatus4);
  console.log('Rejection reason cleared:', status4.body.gst?.rejection_reason === null);
  if (curStatus4 === 'Pending' && status4.body.gst?.rejection_reason === null) {
    console.log('✓ PASS: Status automatically transitioned back to "Pending" upon PDF resubmission!\n');
  } else {
    throw new Error('Status transition failed on resubmission');
  }

  // 8. Admin approves request
  console.log('[8/8] Admin approving verification request...');
  const appRes = await makeRequest('POST', `/api/admin/sellers/gst/${sellerId}/approve`, {
    'Authorization': `Bearer ${adminToken}`,
    'Content-Type': 'application/json'
  }, Buffer.from(JSON.stringify({ notes: 'Clear document verified successfully.' })));
  console.log('Approve HTTP:', appRes.status, 'Status:', appRes.body.new_status);

  // Seller checks final approved status
  const status5 = await makeRequest('GET', '/api/seller/gst', {
    'Authorization': `Bearer ${sellerToken}`
  });
  const curStatus5 = status5.body.gst_status || status5.body.gst?.status;
  console.log('Seller final status:', curStatus5);
  console.log('Seller message:', status5.body.message || 'Status: ' + curStatus5);
  if (curStatus5 === 'Approved') {
    console.log('✓ PASS: Seller sees current status is "Approved"!\n');
  } else {
    throw new Error('Final approval check failed');
  }

  console.log('================================================================');
  console.log('ALL PDF-ONLY GST TESTS PASSED PERFECTLY!');
  console.log('================================================================');
}

runPdfOnlyGstTest().catch(err => {
  console.error('Test failed:', err);
  process.exit(1);
});
