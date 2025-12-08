# API Chuẩn Hóa Dữ Liệu

## Tổng Quan
API này cung cấp các hàm chuẩn hóa dữ liệu sản phẩm (loại giày, màu sắc, thương hiệu) từ frontend.

**Version**: 2.0.0  
**Endpoint**: `api_chuan_hoa_du_lieu.php`

## Migration từ v1.0 (JavaScript local)

### Thay Đổi Chính
- ✅ **Logic moved to backend**: 360+ type mappings, 150+ color mappings
- ✅ **Single source of truth**: Chỉ maintain PHP
- ✅ **100% consistency**: Frontend và Backend cùng logic
- ⚠️ **Breaking change**: Functions giờ là **async** (phải dùng `await`)

### Cài Đặt

#### 1. Import API Client (thay cho normalization-utils.js cũ)
```html
<!-- OLD -->
<script src="js/normalization-utils.js"></script>

<!-- NEW -->
<script src="js/normalization-api-client.js"></script>
```

#### 2. Update Code
```javascript
// OLD (synchronous)
const type = standardizeProductType('sneaker');
const color = standardizeColor('black');
const brand = standardizeBrand('nike');

// NEW (asynchronous - MUST use await)
const type = await standardizeProductType('sneaker');
const color = await standardizeColor('black');
const brand = await standardizeBrand('nike');
```

## API Reference

### 1. standardizeProductType(type)
Chuẩn hóa loại sản phẩm

**Input**: `'sneaker'`, `'high heel'`, `'sandal'`, etc.  
**Output**: `'Sneaker'`, `'Giày cao gót'`, `'Sandal'`, etc.

```javascript
const type = await standardizeProductType('running shoe');
console.log(type); // 'Sneaker'
```

**Supported**: 360+ variations
- Sneaker, Boot, Sandal, Giày cao gót, Giày đế xuồng, Giày bệt, Giày lười, Giày tây, etc.

---

### 2. standardizeColor(color)
Chuẩn hóa màu sắc

**Input**: `'black'`, `'red'`, `'navy blue'`, etc.  
**Output**: `'Đen'`, `'Đỏ'`, `'Xanh navy'`, etc.

```javascript
const color = await standardizeColor('black');
console.log(color); // 'Đen'
```

**Supported**: 150+ variations
- Basic: black, white, red, blue, green, yellow, etc.
- Extended: navy, burgundy, coral, mint, lavender, etc.
- Vietnamese: đen, trắng, đỏ, xanh, vàng, etc.

---

### 3. standardizeBrand(brand)
Chuẩn hóa thương hiệu

**Input**: `'nike'`, `'adidas'`, `'no brand'`, etc.  
**Output**: `'Nike'`, `'Adidas'`, `'Unknown'`, etc.

```javascript
const brand = await standardizeBrand('nike');
console.log(brand); // 'Nike'

const unknown = await standardizeBrand('no brand');
console.log(unknown); // 'Unknown'
```

---

### 4. normalizeColors(colors)
Chuẩn hóa danh sách màu sắc

**Input**: `['black', 'white']` hoặc `'black, white'`  
**Output**: `['Đen', 'Trắng']`

```javascript
const colors = await normalizeColors(['black', 'white', 'red']);
console.log(colors); // ['Đen', 'Trắng', 'Đỏ']
```

---

### 5. batchNormalize(data) ⭐ Recommended
Chuẩn hóa nhiều fields cùng lúc (hiệu quả hơn)

**Input**: Object với các field cần chuẩn hóa  
**Output**: Object đã chuẩn hóa

```javascript
const normalized = await batchNormalize({
    type: 'sneaker',
    brand: 'nike',
    colors: ['black', 'white'],
    color: 'red'
});

console.log(normalized);
// {
//   type: 'Sneaker',
//   brand: 'Nike',
//   colors: ['Đen', 'Trắng'],
//   color: 'Đỏ'
// }
```

**Lợi ích**:
- ✅ Chỉ 1 API call thay vì 4 calls
- ✅ Nhanh hơn (reduce network overhead)
- ✅ Atomic operation

---

## Error Handling

API client tự động fallback về giá trị gốc nếu API fail:

```javascript
try {
    const type = await standardizeProductType('sneaker');
    console.log(type); // 'Sneaker'
} catch (error) {
    // API client đã xử lý, trả về 'sneaker' (original)
    console.error('Normalization failed, using original value');
}
```

---

## Backend API (PHP)

### Request Format
```json
POST api_chuan_hoa_du_lieu.php
Content-Type: application/json

{
  "action": "standardizeProductType",
  "value": "sneaker"
}
```

### Response Format (Success)
```json
{
  "success": true,
  "action": "standardizeProductType",
  "original": "sneaker",
  "normalized": "Sneaker"
}
```

### Response Format (Error)
```json
{
  "success": false,
  "error": "Unknown action: invalidAction"
}
```

### Available Actions
- `standardizeProductType`
- `standardizeColor`
- `standardizeBrand`
- `normalizeColors`
- `batch` (chuẩn hóa nhiều fields)

---

## Testing

### Test API Endpoint
```javascript
// Test single normalization
fetch('api_chuan_hoa_du_lieu.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
        action: 'standardizeProductType',
        value: 'sneaker'
    })
})
.then(res => res.json())
.then(data => console.log(data));

// Expected output:
// {
//   "success": true,
//   "action": "standardizeProductType",
//   "original": "sneaker",
//   "normalized": "Sneaker"
// }
```

### Test Batch Processing
```javascript
fetch('api_chuan_hoa_du_lieu.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
        action: 'batch',
        data: {
            type: 'sneaker',
            brand: 'nike',
            colors: ['black', 'white']
        }
    })
})
.then(res => res.json())
.then(data => console.log(data));
```

---

## Performance

### Before (v1.0 - JavaScript local)
- ✅ Synchronous (no network delay)
- ❌ 977 lines JS downloaded
- ❌ Client-side processing
- ❌ Inconsistent với backend

### After (v2.0 - PHP API)
- ⚠️ Asynchronous (network delay ~50-100ms)
- ✅ 155 lines JS downloaded (-84%)
- ✅ Server-side processing (faster)
- ✅ 100% consistent với backend

**Recommendation**: Use `batchNormalize()` to minimize API calls

---

## Troubleshooting

### Issue: Functions return undefined
**Cause**: Quên dùng `await`  
**Fix**:
```javascript
// ❌ Wrong
const type = standardizeProductType('sneaker');

// ✅ Correct
const type = await standardizeProductType('sneaker');
```

### Issue: API returns original value
**Cause**: API endpoint không accessible hoặc PHP error  
**Fix**:
1. Check console log for error messages
2. Test API endpoint directly: `curl -X POST api_chuan_hoa_du_lieu.php`
3. Check PHP error logs

### Issue: CORS error
**Cause**: Cross-origin request blocked  
**Fix**: API đã có CORS headers, nhưng nếu vẫn lỗi:
```php
// Add to api_chuan_hoa_du_lieu.php
header('Access-Control-Allow-Origin: *');
```

---

## Migration Checklist

- [ ] Replace `<script src="js/normalization-utils.js">` with `<script src="js/normalization-api-client.js">`
- [ ] Add `await` to all normalization function calls
- [ ] Wrap calls in `async` function or use `.then()`
- [ ] Replace multiple calls with `batchNormalize()` where possible
- [ ] Test API endpoint với production data
- [ ] Update error handling
- [ ] Test in production environment

---

## Support

**Backend**: `helpers/TroGiupDoTuongDong.php`  
**API**: `api_chuan_hoa_du_lieu.php`  
**Client**: `js/normalization-api-client.js`

**Version**: 2.0.0  
**Last Updated**: 2024-12-08
