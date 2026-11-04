/**
 * Comprehensive Multi-Image Verification Suite
 * Verifies up to 4 images support, adding new images without overwriting slot 0,
 * and editing individual slots.
 */

const BASE_URL = process.env.TEST_URL || 'https://knowlipop.shop';

const IMG1 = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAAEklEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
const IMG2 = 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAACAAIBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';
const IMG3 = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAEklEQVR42mNkYPjPwMDAwMADAAbwAf+v6tWJAAAAAElFTkSuQmCC';
const IMG4 = 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAACAAIBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';
const IMG_REPLACE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

async function makeRequest(path, options = {}) {
  const url = path.startsWith('http') ? path : `${BASE_URL}${path}`;
  const headers = {
    'Accept': 'application/json',
    ...(options.headers || {})
  };
  if (options.body && typeof options.body === 'string' && !headers['Content-Type']) {
    headers['Content-Type'] = 'application/json';
  }

  let attempts = 0;
  while (attempts < 3) {
    attempts++;
    try {
      const response = await fetch(url, { ...options, headers });
      const contentType = response.headers.get('content-type') || '';
      let body = null;
      if (contentType.includes('application/json')) {
        body = await response.json();
      } else {
        body = await response.text();
      }
      return { status: response.status, body, headers: response.headers };
    } catch (err) {
      if (attempts >= 3) throw err;
      await new Promise(res => setTimeout(res, 800 * attempts));
    }
  }
}

async function run() {
  console.log(`=== Multi-Image Persistence and Addition Test Suite ===`);
  console.log(`Target: ${BASE_URL}\n`);

  // 1. Register a Seller
  const suffix = Date.now().toString().slice(-4);
  const sellerPayload = {
    seller_name: `Multi Image Seller ${suffix}`,
    name: `Multi Image Seller ${suffix}`,
    email: `seller_multi_${Date.now()}@vendor.app`,
    phone: `+91${Math.floor(6000000000 + Math.random() * 3999999999)}`,
    address: '100 Tech Park',
    password: 'Password123!',
    password_confirmation: 'Password123!',
    role: 'seller'
  };

  const regRes = await makeRequest('/api/register', {
    method: 'POST',
    body: JSON.stringify(sellerPayload)
  });
  console.log(`[1] Seller Registration: Status ${regRes.status}`);
  if (regRes.status !== 200 && regRes.status !== 201) {
    throw new Error('Registration failed: ' + JSON.stringify(regRes.body));
  }
  const token = regRes.body.token || regRes.body.data?.token;
  const authHeaders = { Authorization: `Bearer ${token}` };

  // 2. Create Product with 1 Image (Simulating initial state: [ 1/1 ])
  const prodRes = await makeRequest('/api/products', {
    method: 'POST',
    headers: authHeaders,
    body: JSON.stringify({
      name: `Multi-Img Jacket ${suffix}`,
      seller_price: 1500,
      stock: 10,
      image: IMG1
    })
  });
  console.log(`[2] Create Product with 1 Image: Status ${prodRes.status}`);
  const createdProd = prodRes.body.product || prodRes.body.data || prodRes.body;
  const prodId = createdProd.id;
  const initialImg1 = createdProd.image_url;
  console.log(`    Product ID: ${prodId}`);
  console.log(`    Images count: ${createdProd.images.length} (Initial: [ 1/1 ])`);
  console.log(`    Image 1 URL: ${initialImg1}`);

  if (createdProd.images.length !== 1) {
    throw new Error(`Expected exactly 1 image initially, got ${createdProd.images.length}`);
  }

  // 3. Add 2nd Image to Product via POST /api/products/:id/images (Testing "Add New Image" in field)
  // Must NOT overwrite slot 0, must become [ 1/2 ]
  const addImg2Res = await makeRequest(`/api/products/${prodId}/images`, {
    method: 'POST',
    headers: authHeaders,
    body: JSON.stringify({
      image: IMG2
    })
  });
  console.log(`[3] Add 2nd Image via POST /api/products/${prodId}/images: Status ${addImg2Res.status}`);
  const img2Data = addImg2Res.body.product || addImg2Res.body;
  console.log(`    Images count now: ${img2Data.images.length}`);
  console.log(`    Slot 0: ${img2Data.images[0]}`);
  console.log(`    Slot 1: ${img2Data.images[1]}`);

  if (img2Data.images.length !== 2) {
    throw new Error(`Expected 2 images after adding 2nd image, got ${img2Data.images.length}`);
  }
  if (img2Data.images[0] !== initialImg1) {
    throw new Error(`Slot 0 was overwritten! Expected ${initialImg1}, got ${img2Data.images[0]}`);
  }
  const img2Url = img2Data.images[1];

  // 4. Add 3rd Image via POST /api/products/:id/upload
  // Must become [ 1/3 ]
  const addImg3Res = await makeRequest(`/api/products/${prodId}/upload`, {
    method: 'POST',
    headers: authHeaders,
    body: JSON.stringify({
      image: IMG3
    })
  });
  console.log(`[4] Add 3rd Image via POST /api/products/${prodId}/upload: Status ${addImg3Res.status}`);
  const img3Data = addImg3Res.body.product || addImg3Res.body;
  console.log(`    Images count now: ${img3Data.images.length}`);
  if (img3Data.images.length !== 3) {
    throw new Error(`Expected 3 images after adding 3rd image, got ${img3Data.images.length}`);
  }
  const img3Url = img3Data.images[2];

  // 5. Add 4th Image via PUT /api/products/:id (Simulating "Edit Details" and adding 4th photo)
  // Must become [ 1/4 ]
  const addImg4Res = await makeRequest(`/api/products/${prodId}`, {
    method: 'PUT',
    headers: authHeaders,
    body: JSON.stringify({
      name: `Multi-Img Jacket ${suffix} (Updated)`,
      stock: 12,
      image: IMG4
    })
  });
  console.log(`[5] Add 4th Image via PUT /api/products/${prodId}: Status ${addImg4Res.status}`);
  const img4Data = addImg4Res.body.product || addImg4Res.body;
  console.log(`    Images count now: ${img4Data.images.length}`);
  if (img4Data.images.length !== 4) {
    throw new Error(`Expected 4 images after adding 4th image, got ${img4Data.images.length}`);
  }
  const img4Url = img4Data.images[3];

  // 6. Edit/Replace Image at Slot 1 (image_index = 1)
  // Must replace ONLY slot 1 without touching slot 0, slot 2, slot 3
  const replaceSlot1Res = await makeRequest(`/api/products/${prodId}/images`, {
    method: 'POST',
    headers: authHeaders,
    body: JSON.stringify({
      image: IMG_REPLACE,
      image_index: 1
    })
  });
  console.log(`[6] Replace Slot 1 via image_index: 1: Status ${replaceSlot1Res.status}`);
  const replacedData = replaceSlot1Res.body.product || replaceSlot1Res.body;
  console.log(`    Images count: ${replacedData.images.length}`);
  console.log(`    Slot 0: ${replacedData.images[0]}`);
  console.log(`    Slot 1 (Replaced): ${replacedData.images[1]}`);
  console.log(`    Slot 2: ${replacedData.images[2]}`);
  console.log(`    Slot 3: ${replacedData.images[3]}`);

  if (replacedData.images.length !== 4) {
    throw new Error(`Expected 4 images after slot replacement, got ${replacedData.images.length}`);
  }
  if (replacedData.images[0] !== initialImg1) {
    throw new Error(`Slot 0 was altered during slot 1 replacement!`);
  }
  if (replacedData.images[1] === img2Url) {
    throw new Error(`Slot 1 was not replaced!`);
  }
  if (replacedData.images[2] !== img3Url || replacedData.images[3] !== img4Url) {
    throw new Error(`Slots 2 or 3 were altered during slot 1 replacement!`);
  }

  // 7. Test Retrieval via GET /api/products/:id (App Refresh Simulation)
  const getProdRes = await makeRequest(`/api/products/${prodId}`, {
    method: 'GET'
  });
  console.log(`[7] Retrieve Product Details (App Refresh): Status ${getProdRes.status}`);
  const fetchedProd = getProdRes.body.product || getProdRes.body.data || getProdRes.body;
  console.log(`    Retrieved images: ${fetchedProd.images.length}`);
  console.log(`    Gallery Items: ${fetchedProd.gallery_items?.length || 0}`);
  if (fetchedProd.images.length !== 4) {
    throw new Error(`Expected 4 images on retrieval, got ${fetchedProd.images.length}`);
  }

  // 8. Test Retrieval via GET /api/products/:id/images
  const getImagesRes = await makeRequest(`/api/products/${prodId}/images`, {
    method: 'GET'
  });
  console.log(`[8] Dedicated Images API GET /api/products/${prodId}/images: Status ${getImagesRes.status}`);
  console.log(`    Images count: ${getImagesRes.body.images?.length || 0}`);
  console.log(`    Gallery count: ${getImagesRes.body.gallery?.length || 0}`);
  if (getImagesRes.body.images.length !== 4) {
    throw new Error(`Dedicated API images count mismatch! Expected 4, got ${getImagesRes.body.images.length}`);
  }

  // 9. Verify all 4 images return HTTP 200 OK from server storage
  console.log(`[9] Verifying permanent image URLs accessibility:`);
  for (let i = 0; i < fetchedProd.images.length; i++) {
    const imgUrl = fetchedProd.images[i];
    const ping = await makeRequest(imgUrl, { method: 'HEAD' });
    console.log(`    [Slot ${i}] ${imgUrl} -> Status ${ping.status}`);
    if (ping.status !== 200) {
      throw new Error(`Image ${imgUrl} returned status ${ping.status}`);
    }
  }

  console.log(`\n>>> ALL 9 MULTI-IMAGE TESTS PASSED PERFECTLY! <<<`);
}

run().catch(err => {
  console.error('\n*** TEST FAILED ***:', err.message);
  process.exit(1);
});
