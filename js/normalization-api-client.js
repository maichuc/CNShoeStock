/**
 * ============================================
 * NORMALIZATION API CLIENT
 * ============================================
 * Client-side wrapper để gọi API chuẩn hóa PHP
 * Thay thế cho normalization-utils.js (logic moved to backend)
 * 
 * @version 2.0.0
 * @date 2024-12-08
 */

(function(window) {
    'use strict';

    const API_ENDPOINT = 'api_chuan_hoa_du_lieu.php';

    /**
     * Gọi API chuẩn hóa
     * @private
     */
    async function callNormalizationAPI(action, value) {
        try {
            const response = await fetch(API_ENDPOINT, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ action, value })
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.error || 'Unknown error');
            }

            return result.normalized;
        } catch (error) {
            console.error(`❌ Normalization API error (${action}):`, error);
            // Fallback: return original value if API fails
            return value;
        }
    }

    /**
     * Gọi API batch (normalize nhiều fields cùng lúc)
     * @private
     */
    async function callBatchNormalizationAPI(data) {
        try {
            const response = await fetch(API_ENDPOINT, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ action: 'batch', data })
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.error || 'Unknown error');
            }

            return result.normalized;
        } catch (error) {
            console.error('❌ Batch normalization API error:', error);
            return data; // Fallback: return original data
        }
    }

    /**
     * Chuẩn hóa loại sản phẩm (Product Type)
     * @param {string} type - Loại sản phẩm cần chuẩn hóa
     * @returns {Promise<string>} - Loại sản phẩm đã chuẩn hóa
     */
    async function standardizeProductType(type) {
        if (!type) return '';
        return await callNormalizationAPI('standardizeProductType', type);
    }

    /**
     * Chuẩn hóa màu sắc (Color)
     * @param {string} color - Màu sắc cần chuẩn hóa
     * @returns {Promise<string>} - Màu sắc đã chuẩn hóa
     */
    async function standardizeColor(color) {
        if (!color) return '';
        return await callNormalizationAPI('standardizeColor', color);
    }

    /**
     * Chuẩn hóa thương hiệu (Brand)
     * @param {string} brand - Thương hiệu cần chuẩn hóa
     * @returns {Promise<string>} - Thương hiệu đã chuẩn hóa
     */
    async function standardizeBrand(brand) {
        if (!brand || !brand.trim()) return 'Unknown';
        return await callNormalizationAPI('standardizeBrand', brand);
    }

    /**
     * Chuẩn hóa danh sách màu sắc
     * @param {Array|string} colors - Danh sách màu hoặc chuỗi màu
     * @returns {Promise<Array>} - Mảng màu đã chuẩn hóa
     */
    async function normalizeColors(colors) {
        if (!colors) return [];
        return await callNormalizationAPI('normalizeColors', colors);
    }

    /**
     * Batch normalization - Chuẩn hóa nhiều fields cùng lúc (hiệu quả hơn)
     * @param {Object} data - Object chứa các field cần chuẩn hóa
     * @returns {Promise<Object>} - Object đã chuẩn hóa
     * 
     * @example
     * const normalized = await batchNormalize({
     *   type: 'sneaker',
     *   brand: 'nike',
     *   colors: ['black', 'white']
     * });
     * // Returns: { type: 'Sneaker', brand: 'Nike', colors: ['Đen', 'Trắng'] }
     */
    async function batchNormalize(data) {
        return await callBatchNormalizationAPI(data);
    }

    // Export functions to global scope
    window.NormalizationUtils = {
        standardizeProductType: standardizeProductType,
        standardizeColor: standardizeColor,
        standardizeBrand: standardizeBrand,
        normalizeColors: normalizeColors,
        batchNormalize: batchNormalize
    };

    // Backward compatibility - export to window directly
    window.standardizeProductType = standardizeProductType;
    window.standardizeColor = standardizeColor;
    window.standardizeBrand = standardizeBrand;
    window.normalizeColors = normalizeColors;
    window.batchNormalize = batchNormalize;

    console.log('✅ Normalization API Client loaded (v2.0 - Backend-powered)');

})(window);
