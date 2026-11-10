'use strict';

const fs = require('fs');
const path = require('path');
const { execFile } = require('child_process');
const { pathToFileURL } = require('url');

const MAX_IMAGE_BYTES = 15 * 1024 * 1024;
const MAX_IMAGE_DIM = 25000;
const MAX_IMAGE_PIXELS = 100000000;

class ValidationImageError extends Error {
  constructor(message) {
    super(message);
    this.name = 'ValidationImageError';
    this.isValidation = true;
  }
}

let sharpAttempted = false;
let sharpModule = null;
let sharpError = null;

function getSharpModule() {
  if (sharpAttempted) return sharpModule;
  sharpAttempted = true;
  try {
    if (!process.env.VIPS_CONCURRENCY) {
      process.env.VIPS_CONCURRENCY = '1';
    }
    const s = require('sharp');
    if (typeof s.concurrency === 'function') s.concurrency(1);
    if (typeof s.cache === 'function') s.cache(false);
    sharpModule = s;
  } catch (err) {
    sharpError = err && err.message ? err.message : String(err);
    sharpModule = null;
  }
  return sharpModule;
}

let webpWasmModule = null;
let bmpJsModule = null;
let pngjsModule = null;
let jpegJsModule = null;
let omggifModule = null;
let utifModule = null;
let heicDecodeModule = null;
let resvgInitPromise = null;
let resvgModule = null;
let avifDecodePromise = null;

function ensureImageDataGlobal() {
  if (typeof globalThis.ImageData !== 'function') {
    globalThis.ImageData = class ImageData {
      constructor(data, width, height) {
        this.data = data;
        this.width = width;
        this.height = height;
      }
    };
  }
}

function getWebpWasm() {
  if (!webpWasmModule) {
    webpWasmModule = require('webp-wasm');
  }
  return webpWasmModule;
}

function getBmpJs() {
  if (!bmpJsModule) {
    bmpJsModule = require('bmp-js');
  }
  return bmpJsModule;
}

function getPngJs() {
  if (!pngjsModule) {
    pngjsModule = require('pngjs').PNG;
  }
  return pngjsModule;
}

function getJpegJs() {
  if (!jpegJsModule) {
    jpegJsModule = require('jpeg-js');
  }
  return jpegJsModule;
}

function getOmggif() {
  if (!omggifModule) {
    omggifModule = require('omggif');
  }
  return omggifModule;
}

function getUtif() {
  if (!utifModule) {
    utifModule = require('utif');
  }
  return utifModule;
}

function getHeicDecode() {
  if (!heicDecodeModule) {
    heicDecodeModule = require('heic-decode');
  }
  return heicDecodeModule;
}

async function getResvgModule() {
  if (resvgModule && resvgInitPromise) {
    await resvgInitPromise;
    return resvgModule;
  }
  const mod = require('@resvg/resvg-wasm');
  if (!resvgInitPromise) {
    const wasmBytes = fs.readFileSync(require.resolve('@resvg/resvg-wasm/index_bg.wasm'));
    resvgInitPromise = mod.initWasm(wasmBytes).catch((err) => {
      // Allow ignoring duplicate init error if module state was initialized elsewhere
      if (err && /Already initialized/i.test(String(err.message || err))) return;
      resvgInitPromise = null;
      throw err;
    });
  }
  await resvgInitPromise;
  resvgModule = mod;
  return resvgModule;
}

async function decodeAvifViaWasm(avifBuf) {
  ensureImageDataGlobal();
  if (!avifDecodePromise) {
    avifDecodePromise = (async () => {
      const avifPkgDir = path.dirname(require.resolve('@jsquash/avif/package.json'));
      const mod = await import(pathToFileURL(path.join(avifPkgDir, 'decode.js')).href);
      const wasmBytes = fs.readFileSync(path.join(avifPkgDir, 'codec', 'dec', 'avif_dec.wasm'));
      const compiled = await WebAssembly.compile(wasmBytes);
      await mod.init(compiled);
      return mod.default;
    })().catch((err) => {
      avifDecodePromise = null;
      throw err;
    });
  }
  const decodeFn = await avifDecodePromise;
  const copy = Uint8Array.from(avifBuf);
  const decoded = await decodeFn(copy.buffer);
  if (!decoded || !decoded.data || !decoded.width || !decoded.height) {
    throw new Error('Failed to decode AVIF image.');
  }
  checkDimensions(decoded.width, decoded.height, 'AVIF');
  return {
    data: decoded.data instanceof Uint8ClampedArray ? decoded.data : new Uint8ClampedArray(decoded.data),
    width: decoded.width,
    height: decoded.height
  };
}

function checkDimensions(width, height, label = 'Image') {
  if (!Number.isFinite(width) || !Number.isFinite(height) || width <= 0 || height <= 0 ||
      width > MAX_IMAGE_DIM || height > MAX_IMAGE_DIM || (width * height) > MAX_IMAGE_PIXELS) {
    throw new ValidationImageError(`${label} dimensions out of allowed range.`);
  }
}

function isWebpBuffer(buf) {
  return Buffer.isBuffer(buf) && buf.length >= 12 &&
    buf.toString('ascii', 0, 4) === 'RIFF' &&
    buf.toString('ascii', 8, 12) === 'WEBP';
}

function isAnimatedWebpBuffer(buf) {
  if (!isWebpBuffer(buf) || buf.length < 30) return false;
  if (buf.toString('ascii', 12, 16) !== 'VP8X') return false;
  const flags = buf[20];
  return (flags & 0x02) !== 0 || buf.subarray(12, Math.min(buf.length, 96)).includes(Buffer.from('ANIM'));
}

function validateAnimatedWebpChunks(buf) {
  if (!isWebpBuffer(buf) || buf.length < 30) return false;
  const riffSize = buf.readUInt32LE(4);
  if (riffSize < 12 || riffSize + 8 > buf.length + 1) return false;
  if (buf.toString('ascii', 12, 16) !== 'VP8X') return false;
  const wMinus1 = buf[24] | (buf[25] << 8) | (buf[26] << 16);
  const hMinus1 = buf[27] | (buf[28] << 8) | (buf[29] << 16);
  checkDimensions(wMinus1 + 1, hMinus1 + 1, 'WebP');
  let pos = 12;
  let seenAnim = false;
  let seenAnmf = false;
  const limit = Math.min(buf.length, riffSize + 8);
  while (pos + 8 <= limit) {
    const fourcc = buf.toString('ascii', pos, pos + 4);
    const sz = buf.readUInt32LE(pos + 4);
    if (pos + 8 + sz > limit) return false;
    if (fourcc === 'ANIM') seenAnim = true;
    if (fourcc === 'ANMF') seenAnmf = true;
    pos += 8 + sz + (sz % 2);
  }
  return seenAnim && seenAnmf;
}

function isSvgBuffer(buf) {
  if (!Buffer.isBuffer(buf) || buf.length < 10) return false;
  const headSlice = buf.subarray(0, Math.min(buf.length, 512));
  if (headSlice.includes(0)) return false;
  const head = headSlice.toString('utf8').trimStart().replace(/^\uFEFF/, '').toLowerCase();
  return head.startsWith('<svg') || (head.startsWith('<?xml') && head.includes('<svg'));
}

function isSafeSvgBuffer(buf) {
  const fullText = buf.toString('utf8');
  return !/<script[\s>]|<!entity|javascript:|vbscript:|<foreignobject|\son[a-z]+\s*=/i.test(fullText);
}

function getIsobmffBrand(buf) {
  if (!Buffer.isBuffer(buf) || buf.length < 16) return null;
  if (buf.toString('ascii', 4, 8) !== 'ftyp') return null;
  return buf.toString('ascii', 8, 12).toLowerCase();
}

function isHeicBrand(brand) {
  return Boolean(brand && ['heic', 'heix', 'hevc', 'hevx', 'mif1', 'msf1'].includes(brand));
}

function isAvifBrand(brand) {
  return Boolean(brand && ['avif', 'avis'].includes(brand));
}

function extractIcoPayload(buf) {
  if (!Buffer.isBuffer(buf) || buf.length < 22) return null;
  if (buf.readUInt16LE(0) !== 0 || buf.readUInt16LE(2) !== 1) return null;
  const count = buf.readUInt16LE(4);
  if (count < 1 || count > 64 || 6 + count * 16 > buf.length) return null;
  let best = null;
  let bestScore = -1;
  for (let i = 0; i < count; i++) {
    const entryOffset = 6 + i * 16;
    const w = buf[entryOffset] === 0 ? 256 : buf[entryOffset];
    const h = buf[entryOffset + 1] === 0 ? 256 : buf[entryOffset + 1];
    const bpp = buf.readUInt16LE(entryOffset + 6);
    const bytesInRes = buf.readUInt32LE(entryOffset + 8);
    const imageOffset = buf.readUInt32LE(entryOffset + 12);
    if (bytesInRes < 8 || imageOffset + bytesInRes > buf.length) continue;
    const score = (w * h * 64) + bpp;
    if (score > bestScore) {
      bestScore = score;
      best = buf.subarray(imageOffset, imageOffset + bytesInRes);
    }
  }
  if (!best) return null;
  if (best.length >= 8 && best.subarray(0, 8).equals(Buffer.from([0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A]))) {
    return best;
  }
  if (best.length >= 40 && best.readUInt32LE(0) === 40) {
    const width = best.readInt32LE(4);
    const doubleHeight = best.readInt32LE(8);
    const height = Math.floor(Math.abs(doubleHeight) / 2);
    const bpp = best.readUInt16LE(14);
    const colorsUsed = best.readUInt32LE(32);
    if (width > 0 && height > 0) {
      const paletteCount = bpp <= 8 ? (colorsUsed > 0 ? colorsUsed : (1 << bpp)) : 0;
      const header = Buffer.alloc(14);
      header.write('BM', 0, 2, 'ascii');
      header.writeUInt32LE(14 + best.length, 2);
      header.writeUInt32LE(14 + 40 + paletteCount * 4, 10);
      const patchedDib = Buffer.from(best);
      patchedDib.writeInt32LE(doubleHeight < 0 ? -height : height, 8);
      return Buffer.concat([header, patchedDib]);
    }
  }
  return null;
}

function decodeBmpToRgba(bmpBuffer) {
  if (!Buffer.isBuffer(bmpBuffer) || bmpBuffer.length < 26 || bmpBuffer[0] !== 0x42 || bmpBuffer[1] !== 0x4D) {
    throw new ValidationImageError('Invalid BMP image.');
  }
  const bmpWidth = bmpBuffer.readInt32LE(18);
  const bmpHeight = Math.abs(bmpBuffer.readInt32LE(22));
  checkDimensions(bmpWidth, bmpHeight, 'BMP');
  const decoded = getBmpJs().decode(bmpBuffer);
  if (!decoded || !decoded.data || decoded.width <= 0 || decoded.height <= 0) {
    throw new Error('Failed to decode BMP pixels.');
  }
  checkDimensions(decoded.width, decoded.height, 'BMP');
  const abgr = decoded.data;
  const rgba = new Uint8ClampedArray(abgr.length);
  let hasAlpha = false;
  if (decoded.bitPP === 32) {
    for (let i = 0; i < abgr.length; i += 4) {
      if (abgr[i] !== 0) {
        hasAlpha = true;
        break;
      }
    }
  }
  for (let i = 0; i < abgr.length; i += 4) {
    rgba[i] = abgr[i + 3];
    rgba[i + 1] = abgr[i + 2];
    rgba[i + 2] = abgr[i + 1];
    rgba[i + 3] = (decoded.bitPP === 32 && hasAlpha) ? abgr[i] : 255;
  }
  return { data: rgba, width: decoded.width, height: decoded.height };
}

function getJpegExifOrientation(buf) {
  if (!Buffer.isBuffer(buf) || buf.length < 12 || buf[0] !== 0xFF || buf[1] !== 0xD8) {
    return 1;
  }
  let offset = 2;
  while (offset + 4 <= buf.length) {
    if (buf[offset] !== 0xFF) break;
    while (offset < buf.length && buf[offset] === 0xFF) offset++;
    if (offset >= buf.length) break;
    const marker = buf[offset++];
    if (marker === 0xD9 || marker === 0xDA) break; // EOI or SOS
    if (marker === 0x01 || (marker >= 0xD0 && marker <= 0xD7)) continue;
    if (offset + 2 > buf.length) break;
    const segLen = buf.readUInt16BE(offset);
    if (segLen < 2 || offset + segLen > buf.length) break;
    if (marker === 0xE1 && segLen >= 16 && buf.toString('ascii', offset + 2, offset + 8) === 'Exif\0\0') {
      const tiffStart = offset + 8;
      if (tiffStart + 8 > offset + segLen) return 1;
      const endian = buf.toString('ascii', tiffStart, tiffStart + 2);
      const le = endian === 'II';
      if (!le && endian !== 'MM') return 1;
      const readU16 = (p) => le ? buf.readUInt16LE(p) : buf.readUInt16BE(p);
      const readU32 = (p) => le ? buf.readUInt32LE(p) : buf.readUInt32BE(p);
      if (readU16(tiffStart + 2) !== 0x002A) return 1;
      const ifd0Offset = readU32(tiffStart + 4);
      const ifd0 = tiffStart + ifd0Offset;
      if (ifd0 + 2 > offset + segLen) return 1;
      const entries = readU16(ifd0);
      for (let i = 0; i < entries; i++) {
        const entryPos = ifd0 + 2 + i * 12;
        if (entryPos + 12 > offset + segLen) break;
        const tag = readU16(entryPos);
        if (tag === 0x0112) {
          const val = readU16(entryPos + 8);
          return (val >= 1 && val <= 8) ? val : 1;
        }
      }
      return 1;
    }
    offset += segLen;
  }
  return 1;
}

function applyOrientationToRgba(src, width, height, orientation) {
  if (!orientation || orientation === 1) {
    return { data: src, width, height };
  }
  const rotatedDims = orientation >= 5 && orientation <= 8;
  const outW = rotatedDims ? height : width;
  const outH = rotatedDims ? width : height;
  const dst = new Uint8ClampedArray(outW * outH * 4);

  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      let nx = x;
      let ny = y;
      switch (orientation) {
        case 2: nx = width - 1 - x; ny = y; break;
        case 3: nx = width - 1 - x; ny = height - 1 - y; break;
        case 4: nx = x; ny = height - 1 - y; break;
        case 5: nx = y; ny = x; break;
        case 6: nx = height - 1 - y; ny = x; break;
        case 7: nx = height - 1 - y; ny = width - 1 - x; break;
        case 8: nx = y; ny = width - 1 - x; break;
      }
      const sIdx = (y * width + x) * 4;
      const dIdx = (ny * outW + nx) * 4;
      dst[dIdx] = src[sIdx];
      dst[dIdx + 1] = src[sIdx + 1];
      dst[dIdx + 2] = src[sIdx + 2];
      dst[dIdx + 3] = src[sIdx + 3];
    }
  }
  return { data: dst, width: outW, height: outH };
}

async function encodeRgbaWithWebpWasm(rgbaImg, sharpInstance = null) {
  checkDimensions(rgbaImg.width, rgbaImg.height, 'Image');
  if (sharpInstance) {
    try {
      const rawBuf = Buffer.from(rgbaImg.data.buffer, rgbaImg.data.byteOffset, rgbaImg.data.byteLength);
      const out = await sharpInstance(rawBuf, { raw: { width: rgbaImg.width, height: rgbaImg.height, channels: 4 } })
        .webp({ quality: 85, effort: 4 })
        .toBuffer();
      if (isWebpBuffer(out)) return out;
    } catch (_) {}
  }

  const webpWasm = getWebpWasm();
  const data = rgbaImg.data instanceof Uint8ClampedArray ? rgbaImg.data : new Uint8ClampedArray(rgbaImg.data);
  const encoded = await webpWasm.encode(
    { data, width: rgbaImg.width, height: rgbaImg.height },
    { quality: 85, method: 4, alpha_compression: 1, alpha_quality: 100 }
  );
  if (!isWebpBuffer(encoded)) {
    throw new Error('WASM WebP encoder failed to produce valid WEBP output.');
  }
  return encoded;
}

async function decodeAnyToRgbaViaJsWasm(buf) {
  // 1. PNG
  if (buf.length >= 24 && buf.subarray(0, 8).equals(Buffer.from([0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A]))) {
    const ihdrW = buf.readUInt32BE(16);
    const ihdrH = buf.readUInt32BE(20);
    checkDimensions(ihdrW, ihdrH, 'PNG');
    const PNG = getPngJs();
    const decoded = PNG.sync.read(buf);
    checkDimensions(decoded.width, decoded.height, 'PNG');
    return { data: new Uint8ClampedArray(decoded.data), width: decoded.width, height: decoded.height };
  }

  // 2. JPEG / JFIF
  if (buf.length >= 10 && buf[0] === 0xFF && buf[1] === 0xD8 && buf[2] === 0xFF) {
    const orientation = getJpegExifOrientation(buf);
    const decoded = getJpegJs().decode(buf, {
      useTArray: true,
      formatAsRGBA: true,
      tolerantDecoding: true,
      maxResolutionInMP: 100,
      maxMemoryUsageInMB: 256
    });
    if (!decoded || !decoded.data || decoded.width <= 0 || decoded.height <= 0) {
      throw new Error('Failed to decode JPEG pixels.');
    }
    checkDimensions(decoded.width, decoded.height, 'JPEG');
    const oriented = applyOrientationToRgba(
      decoded.data instanceof Uint8ClampedArray ? decoded.data : new Uint8ClampedArray(decoded.data),
      decoded.width,
      decoded.height,
      orientation
    );
    return oriented;
  }

  // 3. GIF (frame 0 with transparency preserved)
  const head6 = buf.toString('ascii', 0, 6);
  if (head6 === 'GIF87a' || head6 === 'GIF89a') {
    const { GifReader } = getOmggif();
    const reader = new GifReader(buf);
    checkDimensions(reader.width, reader.height, 'GIF');
    if (reader.numFrames() < 1) {
      throw new Error('GIF contains no image frames.');
    }
    const pixels = new Uint8ClampedArray(reader.width * reader.height * 4);
    reader.decodeAndBlitFrameRGBA(0, pixels);
    return { data: pixels, width: reader.width, height: reader.height };
  }

  // 4. TIFF (II* or MM*)
  if ((buf[0] === 0x49 && buf[1] === 0x49 && buf[2] === 0x2A && buf[3] === 0x00) ||
      (buf[0] === 0x4D && buf[1] === 0x4D && buf[2] === 0x00 && buf[3] === 0x2A)) {
    const UTIF = getUtif();
    const ab = buf.buffer.slice(buf.byteOffset, buf.byteOffset + buf.byteLength);
    const ifds = UTIF.decode(ab);
    if (!ifds || ifds.length === 0) {
      throw new Error('Invalid TIFF image structure.');
    }
    const preW = Array.isArray(ifds[0].t256) ? ifds[0].t256[0] : (ifds[0].width || 0);
    const preH = Array.isArray(ifds[0].t257) ? ifds[0].t257[0] : (ifds[0].height || 0);
    if (preW > 0 && preH > 0) {
      checkDimensions(preW, preH, 'TIFF');
    }
    UTIF.decodeImage(ab, ifds[0]);
    const w = ifds[0].width;
    const h = ifds[0].height;
    checkDimensions(w, h, 'TIFF');
    const rgba = UTIF.toRGBA8(ifds[0]);
    if (!rgba || rgba.length !== w * h * 4) {
      throw new Error('Failed to decode TIFF pixel data.');
    }
    return { data: new Uint8ClampedArray(rgba), width: w, height: h };
  }

  // 5. WEBP (re-verify pixel stream)
  if (isWebpBuffer(buf)) {
    if (buf.length >= 30 && buf.toString('ascii', 12, 16) === 'VP8X') {
      const w = (buf[24] | (buf[25] << 8) | (buf[26] << 16)) + 1;
      const h = (buf[27] | (buf[28] << 8) | (buf[29] << 16)) + 1;
      checkDimensions(w, h, 'WebP');
    } else if (buf.length >= 30 && buf.toString('ascii', 12, 16) === 'VP8 ' &&
               buf[23] === 0x9D && buf[24] === 0x01 && buf[25] === 0x2A) {
      const w = buf.readUInt16LE(26) & 0x3FFF;
      const h = buf.readUInt16LE(28) & 0x3FFF;
      checkDimensions(w, h, 'WebP');
    } else if (buf.length >= 25 && buf.toString('ascii', 12, 16) === 'VP8L' && buf[20] === 0x2F) {
      const bits = buf.readUInt32LE(21);
      const w = (bits & 0x3FFF) + 1;
      const h = ((bits >> 14) & 0x3FFF) + 1;
      checkDimensions(w, h, 'WebP');
    }
    const webpWasm = getWebpWasm();
    const decoded = await webpWasm.decode(Uint8Array.from(buf));
    if (!decoded || !decoded.data || decoded.width <= 0 || decoded.height <= 0) {
      throw new Error('Failed to decode WEBP pixels.');
    }
    checkDimensions(decoded.width, decoded.height, 'WebP');
    return {
      data: decoded.data instanceof Uint8ClampedArray ? decoded.data : new Uint8ClampedArray(decoded.data),
      width: decoded.width,
      height: decoded.height
    };
  }

  // 6. AVIF
  const brand = getIsobmffBrand(buf);
  if (isAvifBrand(brand)) {
    return decodeAvifViaWasm(buf);
  }

  // 7. HEIC / HEIF
  if (isHeicBrand(brand)) {
    const heicDecode = getHeicDecode();
    const decoded = await heicDecode({ buffer: buf });
    if (!decoded || !decoded.data || !decoded.width || !decoded.height) {
      throw new Error('Failed to decode HEIC/HEIF image.');
    }
    checkDimensions(decoded.width, decoded.height, 'HEIC');
    return {
      data: new Uint8ClampedArray(decoded.data.buffer, decoded.data.byteOffset, decoded.data.byteLength),
      width: decoded.width,
      height: decoded.height
    };
  }

  throw new Error('Unsupported or corrupt image binary data.');
}

async function convertViaPhpCliFallback(rawBuf) {
  const phpCandidates = process.platform === 'win32'
    ? ['C:\\xampp\\php\\php.exe', 'php']
    : ['/usr/bin/php', '/usr/local/bin/php', 'php'];

  const phpCode = `
    $in = stream_get_contents(STDIN);
    if (!$in || strlen($in) < 10) exit(1);
    if (!function_exists('imagewebp')) exit(2);
    $img = @imagecreatefromstring($in);
    if (!$img && substr($in, 0, 2) === 'BM' && function_exists('imagecreatefrombmp')) {
      $t = @tempnam(sys_get_temp_dir(), 'nbmp_');
      if ($t) { @file_put_contents($t, $in); $img = @imagecreatefrombmp($t); @unlink($t); }
    }
    if (!$img) exit(3);
    if (function_exists('imageistruecolor') && !imageistruecolor($img) && function_exists('imagepalettetotruecolor')) {
      @imagepalettetotruecolor($img);
    }
    @imagealphablending($img, false);
    @imagesavealpha($img, true);
    ob_start();
    $ok = @imagewebp($img, null, 85);
    $out = ob_get_clean();
    @imagedestroy($img);
    if (!$ok || !is_string($out) || strlen($out) < 12 || substr($out, 0, 4) !== 'RIFF' || substr($out, 8, 4) !== 'WEBP') exit(4);
    fwrite(STDOUT, $out);
  `;

  for (const bin of phpCandidates) {
    const out = await new Promise((resolve) => {
      const child = execFile(
        bin,
        ['-r', phpCode],
        { encoding: 'buffer', maxBuffer: 20 * 1024 * 1024, timeout: 15000 },
        (err, stdout) => {
          if (err || !Buffer.isBuffer(stdout) || !isWebpBuffer(stdout)) {
            return resolve(null);
          }
          resolve(stdout);
        }
      );
      if (child.stdin) {
        child.stdin.on('error', () => {});
        child.stdin.end(rawBuf);
      }
    });
    if (out) return out;
  }
  return null;
}

async function convertImageBufferToWebp(inputBuffer, meta = {}, options = {}) {
  if (!Buffer.isBuffer(inputBuffer) || inputBuffer.length < 10) {
    throw new ValidationImageError('Image buffer is empty or too small.');
  }
  if (inputBuffer.length > MAX_IMAGE_BYTES) {
    throw new ValidationImageError('Image exceeds maximum 15MB size limit.');
  }

  const forceFallback = Boolean(options.forceFallback);
  const skipPhpFallback = Boolean(options.skipPhpFallback);
  const sharp = forceFallback ? null : getSharpModule();

  // 1. SVG vector image
  if (isSvgBuffer(inputBuffer) || (meta.mimeType && String(meta.mimeType).toLowerCase().includes('svg'))) {
    if (!isSvgBuffer(inputBuffer)) {
      throw new ValidationImageError('Invalid SVG image content.');
    }
    if (!isSafeSvgBuffer(inputBuffer)) {
      throw new ValidationImageError('SVG contains prohibited active content.');
    }
    if (sharp) {
      try {
        const out = await sharp(inputBuffer, { failOn: 'none', density: 150, limitInputPixels: MAX_IMAGE_PIXELS })
          .webp({ quality: 85, effort: 4 })
          .toBuffer();
        if (isWebpBuffer(out)) return out;
      } catch (_) {}
    }
    const { Resvg } = await getResvgModule();
    const resvg = new Resvg(inputBuffer);
    const rendered = resvg.render();
    checkDimensions(rendered.width, rendered.height, 'SVG');
    return encodeRgbaWithWebpWasm({
      data: new Uint8ClampedArray(rendered.pixels),
      width: rendered.width,
      height: rendered.height
    });
  }

  // 2. BMP bitmap image
  if (inputBuffer[0] === 0x42 && inputBuffer[1] === 0x4D) {
    const rgba = decodeBmpToRgba(inputBuffer);
    return encodeRgbaWithWebpWasm(rgba, sharp);
  }

  // 3. ICO icon container
  if (inputBuffer.length >= 22 && inputBuffer.readUInt16LE(0) === 0 && inputBuffer.readUInt16LE(2) === 1) {
    const icoInner = extractIcoPayload(inputBuffer);
    if (!icoInner) {
      throw new ValidationImageError('Unsupported or invalid ICO image.');
    }
    if (icoInner[0] === 0x42 && icoInner[1] === 0x4D) {
      const rgba = decodeBmpToRgba(icoInner);
      const res = await encodeRgbaWithWebpWasm(rgba, sharp);
      return res;
    }
    if (sharp) {
      try {
        const out = await sharp(icoInner, { failOn: 'none', limitInputPixels: MAX_IMAGE_PIXELS })
          .webp({ quality: 85, effort: 4 })
          .toBuffer();
        if (isWebpBuffer(out)) return out;
      } catch (_) {}
    }
    const rgba = await decodeAnyToRgbaViaJsWasm(icoInner);
    return encodeRgbaWithWebpWasm(rgba);
  }

  // 4. HEIC / HEIF (iOS photos — lazy-load WASM decoder only when needed)
  const brand = getIsobmffBrand(inputBuffer);
  if (isHeicBrand(brand)) {
    try {
      const heicDecode = getHeicDecode();
      const decoded = await heicDecode({ buffer: inputBuffer });
      if (!decoded || !decoded.data || !decoded.width || !decoded.height) {
        throw new Error('Failed to decode HEIC/HEIF image.');
      }
      checkDimensions(decoded.width, decoded.height, 'HEIC');
      const rgba = {
        data: new Uint8ClampedArray(decoded.data.buffer, decoded.data.byteOffset, decoded.data.byteLength),
        width: decoded.width,
        height: decoded.height
      };
      const res = await encodeRgbaWithWebpWasm(rgba, sharp);
      return res;
    } catch (heicErr) {
      if (heicErr && heicErr.isValidation) throw heicErr;
      if (!skipPhpFallback) {
        const phpOut = await convertViaPhpCliFallback(inputBuffer);
        if (phpOut && isWebpBuffer(phpOut)) return phpOut;
      }
      throw heicErr;
    }
  }

  // 5. Try native sharp first for JPEG, PNG, WEBP, GIF (preserves animation), TIFF, AVIF
  if (sharp) {
    try {
      const out = await sharp(inputBuffer, { failOn: 'none', animated: true, limitInputPixels: MAX_IMAGE_PIXELS })
        .rotate()
        .webp({ quality: 85, effort: 4 })
        .toBuffer();
      if (isWebpBuffer(out)) return out;
    } catch (_) {}
  }

  // 6. Animated WebP passthrough (verified RIFF ANIM/ANMF chunks) when sharp is unavailable
  if (isAnimatedWebpBuffer(inputBuffer)) {
    if (validateAnimatedWebpChunks(inputBuffer)) {
      return inputBuffer;
    }
    throw new ValidationImageError('Invalid animated WebP image.');
  }

  // 7. Pure-JS + WASM fallback (JPEG+EXIF, PNG, GIF, TIFF, WEBP, AVIF)
  let fallbackErr = null;
  try {
    const rgba = await decodeAnyToRgbaViaJsWasm(inputBuffer);
    return await encodeRgbaWithWebpWasm(rgba);
  } catch (err) {
    if (err && err.isValidation) throw err;
    fallbackErr = err;
  }

  // 8. Final safety net: system PHP GD/Imagick if available
  if (!skipPhpFallback) {
    const phpOut = await convertViaPhpCliFallback(inputBuffer);
    if (phpOut && isWebpBuffer(phpOut)) {
      return phpOut;
    }
  }

  throw fallbackErr || new Error('Image conversion did not produce valid WEBP output.');
}

function bufferLooksLikeSupportedImage(buf) {
  if (!Buffer.isBuffer(buf) || buf.length < 10) return false;
  if (buf[0] === 0xFF && buf[1] === 0xD8 && buf[2] === 0xFF) return true;
  if (buf.subarray(0, 8).equals(Buffer.from([0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A]))) return true;
  const head6 = buf.toString('ascii', 0, 6);
  if (head6 === 'GIF87a' || head6 === 'GIF89a') return true;
  if (isWebpBuffer(buf)) return true;
  if (buf[0] === 0x42 && buf[1] === 0x4D) return true;
  if ((buf[0] === 0x49 && buf[1] === 0x49 && buf[2] === 0x2A && buf[3] === 0x00) ||
      (buf[0] === 0x4D && buf[1] === 0x4D && buf[2] === 0x00 && buf[3] === 0x2A)) return true;
  if (buf.readUInt16LE(0) === 0 && buf.readUInt16LE(2) === 1 && buf.length >= 22) return true;
  const brand = getIsobmffBrand(buf);
  if (brand && ['avif', 'avis', 'heic', 'heix', 'hevc', 'hevx', 'mif1', 'msf1'].includes(brand)) return true;
  if (isSvgBuffer(buf)) return true;
  return false;
}

function getConverterCapabilities() {
  const s = getSharpModule();
  return {
    node: process.version,
    platform: process.platform,
    arch: process.arch,
    sharp_available: Boolean(s),
    ...(sharpError ? { sharp_error: sharpError } : {}),
    wasm_webp_fallback: true
  };
}

async function convertStdinToWebp() {
  const chunks = [];
  process.stdin.on('data', (c) => chunks.push(c));
  process.stdin.on('end', async () => {
    try {
      const buf = Buffer.concat(chunks);
      const out = await convertImageBufferToWebp(buf, {}, { skipPhpFallback: true });
      if (!isWebpBuffer(out)) process.exit(1);
      process.stdout.write(out);
    } catch (e) {
      process.stderr.write(String((e && e.message) || e));
      process.exit(1);
    }
  });
}

module.exports = {
  MAX_IMAGE_BYTES,
  MAX_IMAGE_DIM,
  MAX_IMAGE_PIXELS,
  isWebpBuffer,
  isSvgBuffer,
  isSafeSvgBuffer,
  getIsobmffBrand,
  extractIcoPayload,
  getJpegExifOrientation,
  applyOrientationToRgba,
  bufferLooksLikeSupportedImage,
  convertImageBufferToWebp,
  getConverterCapabilities,
  convertStdinToWebp
};
