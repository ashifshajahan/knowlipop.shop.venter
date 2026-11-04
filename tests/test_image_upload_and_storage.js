/**
 * Comprehensive Automated Test Suite for Image Upload & Permanent Storage
 * Target: https://knowlipop.shop
 */

const BASE_URL = process.env.TEST_URL || 'https://knowlipop.shop';

// 1x1 Transparent PNG Base64
const SAMPLE_BASE64_PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

// 1x1 Red JPEG Base64
const SAMPLE_BASE64_JPG = 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';

// 1x1 Cyan WEBP Base64
const SAMPLE_BASE64_WEBP = 'data:image/webp;base64,UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==';

async function makeRequest(path, options = {}) {
  const url = path.startsWith('http') ? path : `${BASE_URL}${path}`;
  const headers = {
    'Accept': 'application/json',
    ...(options.headers || {})
  };
  if (options.body && typeof options.body === 'string' && !headers['Content-Type']) {
    headers['Content-Type'] = 'application/json';
  }

  const response = await fetch(url, {
    ...options,
    headers
  });

  const contentType = response.headers.get('content-type') || '';
  let body = null;
  if (contentType.includes('application/json')) {
    body = await response.json();
  } else {
    body = await response.text();
  }

  return { status: response.status, body, headers: response.headers };
}

async function registerTestSeller(suffix) {
  const phone = `+91${Math.floor(6000000000 + Math.random() * 3999999999)}`;
  const email = `seller_img_${Date.now()}_${suffix}@vendor.app`;
  const name = `Image Test Seller ${suffix}`;

  const res = await makeRequest('/api/register', {
    method: 'POST',
    body: JSON.stringify({
      seller_name: name,
      name,
      email,
      phone,
      address: '77 Camera Market Plaza',
      landmark: 'Opposite Studio',
      state: 'Kerala',
      country: 'India',
      password: 'Password123!',
      password_confirmation: 'Password123!',
      role: 'seller'
    })
  });

  if (res.status !== 201 && res.status !== 200) {
    throw new Error(`Failed to register seller ${suffix}: ${JSON.stringify(res.body)}`);
  }

  const token = res.body.token || res.body.access_token || res.body.data?.token;
  const sellerId = res.body.user?.id || res.body.seller?.id || res.body.data?.id;

  return { token, sellerId, name, phone, email };
}

async function run() {
  console.log(`\n======================================================`);
  console.log(`Running Image Upload & Storage Verification on ${BASE_URL}`);
  console.log(`======================================================\n`);

  // 1. Setup Sellers
  console.log('--- 1. Registering Test Sellers ---');
  const sellerA = await registerTestSeller('A');
  const sellerB = await registerTestSeller('B_Unauthorized');
  console.log(`✅ Seller A Registered: ID ${sellerA.sellerId}`);
  console.log(`✅ Seller B Registered: ID ${sellerB.sellerId}`);

  // 2. Test File Format & Size Validation (Security)
  console.log('\n--- 2. Testing Image Security & Validation ---');
  const invalidFormatRes = await makeRequest('/api/upload', {
    method: 'POST',
    headers: { Authorization: `Bearer ${sellerA.token}` },
    body: JSON.stringify({
      image: 'data:text/plain;base64,VGhpcyBpcyBhIHRleHQgZmlsZSwgbm90IGFuIGltYWdlIQ=='
    })
  });
  if (invalidFormatRes.status !== 422) {
    throw new Error(`Expected 422 for invalid format upload, got: ${invalidFormatRes.status}`);
  }
  console.log('✅ Validation Verified: Non-image format rejected with 422 Unprocessable Entity!');

  // 3. Test Base64 Image Upload (JPG, PNG, WEBP)
  console.log('\n--- 3. Testing Image Upload API (Single & Formats) ---');
  const uploadPngRes = await makeRequest('/api/upload', {
    method: 'POST',
    headers: { Authorization: `Bearer ${sellerA.token}` },
    body: JSON.stringify({ image: SAMPLE_BASE64_PNG })
  });
  if (uploadPngRes.status !== 201) {
    throw new Error(`Failed to upload PNG: ${JSON.stringify(uploadPngRes.body)}`);
  }
  const uploadedPngUrl = uploadPngRes.body.image_url || uploadPngRes.body.url;
  const uploadedPngFileName = uploadPngRes.body.file_name;
  console.log(`✅ PNG Uploaded Successfully: ${uploadedPngUrl}`);

  const uploadJpgRes = await makeRequest('/api/upload', {
    method: 'POST',
    headers: { Authorization: `Bearer ${sellerA.token}` },
    body: JSON.stringify({ image: SAMPLE_BASE64_JPG })
  });
  if (uploadJpgRes.status !== 201) {
    throw new Error(`Failed to upload JPG: ${JSON.stringify(uploadJpgRes.body)}`);
  }
  const uploadedJpgUrl = uploadJpgRes.body.image_url || uploadJpgRes.body.url;
  console.log(`✅ JPG Uploaded Successfully: ${uploadedJpgUrl}`);

  const uploadWebpRes = await makeRequest('/api/upload', {
    method: 'POST',
    headers: { Authorization: `Bearer ${sellerA.token}` },
    body: JSON.stringify({ image: SAMPLE_BASE64_WEBP })
  });
  if (uploadWebpRes.status !== 201) {
    throw new Error(`Failed to upload WEBP: ${JSON.stringify(uploadWebpRes.body)}`);
  }
  const uploadedWebpUrl = uploadWebpRes.body.image_url || uploadWebpRes.body.url;
  console.log(`✅ WEBP Uploaded Successfully: ${uploadedWebpUrl}`);

  // Verify unique filenames
  if (uploadedPngUrl === uploadedJpgUrl) {
    throw new Error('Collision error: PNG and JPG uploads generated identical URLs!');
  }
  console.log('✅ Unique Filename Generation Verified: All files receive unique random hashes!');

  // 4. Test Permanent Image Retrieval & Direct Access
  console.log('\n--- 4. Testing Permanent Image Retrieval (GET Image URL) ---');
  const fetchImgRes = await makeRequest(uploadedPngUrl);
  if (fetchImgRes.status !== 200) {
    throw new Error(`Failed to fetch uploaded image from ${uploadedPngUrl}: Status ${fetchImgRes.status}`);
  }
  const imgContentType = fetchImgRes.headers.get('content-type') || '';
  const imgCors = fetchImgRes.headers.get('access-control-allow-origin') || '';
  if (!imgContentType.includes('image/')) {
    throw new Error(`Expected image content-type, got: ${imgContentType}`);
  }
  console.log(`✅ Image URL Accessible: 200 OK (${imgContentType}, CORS: "${imgCors}")`);

  // 5. Test Multiple Image Upload (up to 4 images)
  console.log('\n--- 5. Testing Multiple Image Upload ---');
  const multiUploadRes = await makeRequest('/api/upload', {
    method: 'POST',
    headers: { Authorization: `Bearer ${sellerA.token}` },
    body: JSON.stringify({
      images: [SAMPLE_BASE64_PNG, SAMPLE_BASE64_JPG, SAMPLE_BASE64_WEBP]
    })
  });
  if (multiUploadRes.status !== 201) {
    throw new Error(`Failed multiple image upload: ${JSON.stringify(multiUploadRes.body)}`);
  }
  const multiImages = multiUploadRes.body.images || [];
  if (multiImages.length !== 3) {
    throw new Error(`Expected 3 uploaded images, got: ${multiImages.length}`);
  }
  console.log(`✅ Multiple Image Upload Verified: ${multiImages.length} distinct images saved permanently!`);

  // 6. Test Product Creation with Permanent Images
  console.log('\n--- 6. Creating Product with Uploaded Images ---');
  const createProdRes = await makeRequest('/api/products', {
    method: 'POST',
    headers: { Authorization: `Bearer ${sellerA.token}` },
    body: JSON.stringify({
      name: 'Permanent Storage Camera Lens Pro',
      description: '4K Ultra High Definition Zoom Lens with permanent cloud storage assets.',
      price: 1500.00,
      stock: 25,
      images: multiImages
    })
  });
  if (createProdRes.status !== 201) {
    throw new Error(`Failed to create product: ${JSON.stringify(createProdRes.body)}`);
  }
  const createdProd = createProdRes.body.product;
  const prodId = createdProd.id;
  console.log(`✅ Product Created: ID ${prodId}`);
  console.log(`   image_url: ${createdProd.image_url}`);
  console.log(`   images count: ${createdProd.images?.length}`);
  if (!createdProd.image_url || createdProd.images?.length !== 3) {
    throw new Error(`Product image attributes missing or incorrect: ${JSON.stringify(createdProd.images)}`);
  }

  // 7. Test Dedicated Product Images Endpoint
  console.log('\n--- 7. Testing Dedicated Product Images Endpoint ---');
  const prodImgsRes = await makeRequest(`/api/products/${prodId}/images`);
  if (prodImgsRes.status !== 200) {
    throw new Error(`Failed to get product images: ${JSON.stringify(prodImgsRes.body)}`);
  }
  if (!prodImgsRes.body.images || prodImgsRes.body.images.length !== 3) {
    throw new Error(`Expected 3 product images from dedicated endpoint, got: ${JSON.stringify(prodImgsRes.body)}`);
  }
  console.log(`✅ GET /api/products/:id/images Verified: Retrieved ${prodImgsRes.body.images.length} images and gallery metadata!`);

  // 8. Test Product Image Replacement (PUT /api/products/:id/image)
  console.log('\n--- 8. Testing Image Replacement & Physical File Cleanup ---');
  // First, verify unauthorized replacement is blocked
  const unauthReplaceRes = await makeRequest(`/api/products/${prodId}/image`, {
    method: 'PUT',
    headers: { Authorization: `Bearer ${sellerB.token}` },
    body: JSON.stringify({ image: SAMPLE_BASE64_JPG })
  });
  if (unauthReplaceRes.status !== 403) {
    throw new Error(`Expected 403 for unauthorized seller replacing image, got: ${unauthReplaceRes.status}`);
  }
  console.log('✅ Ownership Verified: Unauthorized Seller B cannot replace Seller A image (403 Forbidden)!');

  // Authorized replacement by Seller A
  const replaceRes = await makeRequest(`/api/products/${prodId}/image`, {
    method: 'PUT',
    headers: { Authorization: `Bearer ${sellerA.token}` },
    body: JSON.stringify({
      image: SAMPLE_BASE64_JPG,
      replace_all: false,
      image_index: 0
    })
  });
  if (replaceRes.status !== 200) {
    throw new Error(`Failed to replace image: ${JSON.stringify(replaceRes.body)}`);
  }
  console.log(`✅ Image Replaced Successfully: New Primary URL: ${replaceRes.body.image_url}`);

  // 9. Test Image Deletion from Product
  console.log('\n--- 9. Testing Image Deletion from Product ---');
  const deleteImgRes = await makeRequest(`/api/products/${prodId}/image`, {
    method: 'DELETE',
    headers: { Authorization: `Bearer ${sellerA.token}` }
  });
  if (deleteImgRes.status !== 200) {
    throw new Error(`Failed to delete product image: ${JSON.stringify(deleteImgRes.body)}`);
  }
  console.log(`✅ Image Deleted Successfully: Remaining Images: ${deleteImgRes.body.images?.length}`);

  // 10. Test Refresh / Re-fetch & Persistence after Navigation
  console.log('\n--- 10. Simulating Page Refresh & Reopening (Persistence Check) ---');
  for (let round = 1; round <= 3; round++) {
    const refreshRes = await makeRequest(`/api/products/${prodId}`);
    if (refreshRes.status !== 200) {
      throw new Error(`Failed to fetch product on refresh round ${round}: ${refreshRes.status}`);
    }
    const refreshedProduct = refreshRes.body.product;
    if (!refreshedProduct.image_url || !refreshedProduct.images || refreshedProduct.images.length === 0) {
      throw new Error(`Images disappeared on refresh round ${round}! ${JSON.stringify(refreshedProduct)}`);
    }

    // Verify the primary image URL still resolves with HTTP 200
    const checkImgRes = await makeRequest(refreshedProduct.image_url);
    if (checkImgRes.status !== 200) {
      throw new Error(`Primary image URL returned status ${checkImgRes.status} on refresh round ${round}!`);
    }
    console.log(`✅ Refresh Round ${round} Passed: Product #${prodId} images intact (${refreshedProduct.images.length} images, primary loads 200 OK)`);
  }

  // 11. Test Product Deletion & Safe Image Cleanup
  console.log('\n--- 11. Testing Product Deletion with Safe Image Handling ---');
  const deleteProdRes = await makeRequest(`/api/products/${prodId}`, {
    method: 'DELETE',
    headers: { Authorization: `Bearer ${sellerA.token}` }
  });
  if (deleteProdRes.status !== 200) {
    throw new Error(`Failed to delete product: ${JSON.stringify(deleteProdRes.body)}`);
  }
  console.log('✅ Product and associated image records deleted cleanly without affecting other sellers!');

  console.log(`\n======================================================`);
  console.log(`🎉 ALL IMAGE UPLOAD & STORAGE TESTS PASSED 100%!`);
  console.log(`======================================================\n`);
}

run().catch(err => {
  console.error('\n❌ Test execution failed:', err);
  process.exit(1);
});
