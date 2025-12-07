/**
 * Storage Suggestions Helper
 * Hỗ trợ hiển thị gợi ý vị trí lưu trữ thông minh cho phiếu nhập kho AI
 */

// Global configuration
const STORAGE_SUGGESTIONS_CONFIG = {
    apiUrl: 'api_storage_suggestions.php',
    warehouseId: null, // Sẽ được set từ session
    autoFetch: true,  // Tự động fetch khi có thông tin sản phẩm
    animationDuration: 300
};

/**
 * Khởi tạo warehouse_id từ session
 */
function initStorageSuggestions(warehouseId) {
    STORAGE_SUGGESTIONS_CONFIG.warehouseId = warehouseId;
    console.log('✅ Storage Suggestions Helper initialized with warehouse_id:', warehouseId);
}

/**
 * Fetch gợi ý vị trí lưu trữ từ API
 * @param {Object} productData - Thông tin sản phẩm {product_id, product_type, product_brand, product_name, product_size}
 * @param {Function} callback - Callback function nhận kết quả
 */
function fetchStorageSuggestions(productData, callback) {
    if (!STORAGE_SUGGESTIONS_CONFIG.warehouseId) {
        console.error('❌ Warehouse ID chưa được khởi tạo');
        if (callback) callback({success: false, error: 'Warehouse ID missing'});
        return;
    }

    const requestData = {
        warehouse_id: STORAGE_SUGGESTIONS_CONFIG.warehouseId,
        product_id: productData.product_id || null,
        product_type: productData.product_type || null,
        product_brand: productData.product_brand || null,
        product_name: productData.product_name || null,
        product_size: productData.product_size || null
    };

    console.log('🔍 Fetching storage suggestions for:', requestData);

    $.ajax({
        url: STORAGE_SUGGESTIONS_CONFIG.apiUrl,
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify(requestData),
        dataType: 'json',
        success: function(response) {
            console.log('✅ Storage suggestions received:', response);
            if (callback) callback(response);
        },
        error: function(xhr, status, error) {
            console.error('❌ Error fetching storage suggestions:', error);
            if (callback) callback({success: false, error: error});
        }
    });
}

/**
 * Hiển thị gợi ý vị trí lưu trữ trong UI
 * @param {Object} suggestionData - Dữ liệu gợi ý từ API
 * @param {String} containerId - ID của container để hiển thị
 */
function displayStorageSuggestions(suggestionData, containerId) {
    const container = $('#' + containerId);
    
    if (!container.length) {
        console.error('❌ Container not found:', containerId);
        return;
    }

    let html = '';

    if (suggestionData.success && suggestionData.suggestions && suggestionData.suggestions.length > 0) {
        // Hiển thị header
        html += `
            <div class="card border-left-primary shadow-sm mb-3">
                <div class="card-header bg-primary text-white">
                    <h6 class="m-0">
                        <i class="fas fa-map-marker-alt"></i> ${suggestionData.strategy.phase}: ${suggestionData.strategy.name}
                    </h6>
                    <small>${suggestionData.strategy.description}</small>
                </div>
                <div class="card-body">
                    <div class="alert alert-info mb-3">
                        <i class="fas fa-info-circle"></i> ${suggestionData.message}
                    </div>
                    <div class="accordion" id="storageSuggestionsAccordion">
        `;

        // Hiển thị từng gợi ý vị trí
        suggestionData.suggestions.forEach((suggestion, index) => {
            const isExpanded = index === 0; // Expand item đầu tiên
            html += `
                <div class="card mb-2">
                    <div class="card-header p-0" id="heading${index}">
                        <button class="btn btn-link btn-block text-left ${isExpanded ? '' : 'collapsed'}" 
                                type="button" 
                                data-toggle="collapse" 
                                data-target="#collapse${index}" 
                                aria-expanded="${isExpanded}" 
                                aria-controls="collapse${index}">
                            <div class="d-flex justify-content-between align-items-center">
                                <span>
                                    <i class="fas fa-box text-primary"></i> 
                                    <strong>${suggestion.location_code}</strong>
                                    ${suggestion.description ? ' - ' + suggestion.description : ''}
                                </span>
                                <span class="badge badge-${suggestion.priority === 'High' ? 'success' : 'info'}">
                                    ${suggestion.priority}
                                </span>
                            </div>
                        </button>
                    </div>
                    <div id="collapse${index}" 
                         class="collapse ${isExpanded ? 'show' : ''}" 
                         aria-labelledby="heading${index}" 
                         data-parent="#storageSuggestionsAccordion">
                        <div class="card-body">
                            <h6 class="text-success mb-2">
                                <i class="fas fa-check-circle"></i> Lý do gợi ý:
                            </h6>
                            <ul class="mb-3">
                                ${suggestion.reasoning.map(reason => `<li>${reason}</li>`).join('')}
                            </ul>
                            <button class="btn btn-sm btn-primary select-location-btn" 
                                    data-location-id="${suggestion.location_id}"
                                    data-location-code="${suggestion.location_code}">
                                <i class="fas fa-check"></i> Chọn vị trí này
                            </button>
                        </div>
                    </div>
                </div>
            `;
        });

        html += `
                    </div>
                </div>
            </div>
        `;
    } else if (!suggestionData.success) {
        // Không tìm thấy vị trí phù hợp
        html += `
            <div class="card border-left-warning shadow-sm mb-3">
                <div class="card-header bg-warning text-white">
                    <h6 class="m-0">
                        <i class="fas fa-exclamation-triangle"></i> ${suggestionData.strategy ? suggestionData.strategy.phase : 'Thông báo'}
                    </h6>
                </div>
                <div class="card-body">
                    <div class="alert alert-warning mb-3">
                        <h6>${suggestionData.message}</h6>
                    </div>
        `;

        if (suggestionData.action_required) {
            html += `
                    <div class="alert alert-info">
                        <h6><i class="fas fa-info-circle"></i> ${suggestionData.action_required.title}</h6>
                        <p>${suggestionData.action_required.description}</p>
                        <a href="${suggestionData.action_required.link}" class="btn btn-primary btn-sm">
                            <i class="fas fa-external-link-alt"></i> ${suggestionData.action_required.link_text}
                        </a>
                    </div>
            `;
        }

        html += `
                </div>
            </div>
        `;
    }

    // Hiển thị với animation
    container.html(html).hide().slideDown(STORAGE_SUGGESTIONS_CONFIG.animationDuration);

    // Bind event cho nút chọn vị trí
    $('.select-location-btn').on('click', function() {
        const locationId = $(this).data('location-id');
        const locationCode = $(this).data('location-code');
        selectStorageLocation(locationId, locationCode);
    });
}

/**
 * Xử lý khi user chọn một vị trí lưu trữ
 * @param {Number} locationId - ID của vị trí được chọn
 * @param {String} locationCode - Mã vị trí được chọn
 */
function selectStorageLocation(locationId, locationCode) {
    console.log('📍 Selected storage location:', locationId, locationCode);
    
    // Highlight vị trí được chọn
    $('.select-location-btn').removeClass('btn-success').addClass('btn-primary')
        .html('<i class="fas fa-check"></i> Chọn vị trí này');
    
    $(event.target).removeClass('btn-primary').addClass('btn-success')
        .html('<i class="fas fa-check-circle"></i> Đã chọn');

    // Trigger custom event để các component khác có thể lắng nghe
    $(document).trigger('storageLocationSelected', [{
        locationId: locationId,
        locationCode: locationCode
    }]);

    // Hiển thị thông báo
    showToast('✅ Đã chọn vị trí: ' + locationCode, 'success');
}

/**
 * Helper function để hiển thị toast message
 */
function showToast(message, type) {
    type = type || 'info';
    const bgColor = type === 'success' ? 'bg-success' : 
                    type === 'error' ? 'bg-danger' : 
                    type === 'warning' ? 'bg-warning' : 'bg-info';

    const toast = $(`
        <div class="toast-notification ${bgColor} text-white p-3 rounded shadow" 
             style="position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px;">
            ${message}
        </div>
    `);

    $('body').append(toast);
    
    setTimeout(() => {
        toast.fadeOut(300, function() {
            $(this).remove();
        });
    }, 3000);
}

/**
 * Auto fetch và hiển thị gợi ý khi thêm sản phẩm vào phiếu nhập
 * Gọi function này sau khi thêm một sản phẩm vào danh sách nhập kho
 * @param {Object} productData - Thông tin sản phẩm
 * @param {String} containerId - ID container để hiển thị gợi ý
 */
function autoFetchAndDisplayStorageSuggestions(productData, containerId) {
    if (!STORAGE_SUGGESTIONS_CONFIG.autoFetch) {
        console.log('⏸️ Auto fetch disabled');
        return;
    }

    fetchStorageSuggestions(productData, function(response) {
        displayStorageSuggestions(response, containerId);
    });
}

// Export functions nếu dùng modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        initStorageSuggestions,
        fetchStorageSuggestions,
        displayStorageSuggestions,
        selectStorageLocation,
        autoFetchAndDisplayStorageSuggestions
    };
}
