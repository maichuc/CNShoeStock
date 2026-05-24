/**
 * Stock Receipt Management JavaScript
 * Handles draft creation, editing, and AI analysis
 */

class StockReceiptManager {
    constructor() {
        this.currentDraft = null;
        this.isEditing = false;
        this.aiAnalysisResults = {};
        this.init();
    }

    init() {
        this.bindEvents();
        this.loadDraftFromUrl();
    }

    bindEvents() {
        // Lưu draft button
        $(document).on('click', '#saveDraftBtn', () => this.saveDraft());
        
        // Cập nhật draft button
        $(document).on('click', '#updateDraftBtn', () => this.updateDraft());
        
        // Xác nhận receipt button
        $(document).on('click', '#confirmReceiptBtn', () => this.confirmReceipt());
        
        // AI Analysis button
        $(document).on('click', '#analyzeImageBtn', () => this.analyzeImage());
        
        // Image upload handler
        $(document).on('change', '#productImages', (e) => this.handleImageUpload(e));
        
        // Auto-save every 30 seconds for drafts
        setInterval(() => this.autoSave(), 30000);
    }

    loadDraftFromUrl() {
        const urlParams = new URLSearchParams(window.location.search);
        const editId = urlParams.get('edit');
        
        if (editId) {
            this.loadDraft(editId);
        }
    }

    async loadDraft(receiptId) {
        try {
            const response = await this.apiCall('get_draft', { receipt_id: receiptId });
            
            if (response.success) {
                this.currentDraft = response.receipt;
                this.populateForm(response.receipt, response.items);
                this.isEditing = true;
                this.updateUIForEditMode();
            } else {
                this.showAlert('error', 'Không thể tải phiếu nháp: ' + response.message);
            }
        } catch (error) {
            this.showAlert('error', 'Có lỗi xảy ra khi tải phiếu nháp');
        }
    }

    populateForm(receipt, items) {
        // Populate basic info
        $('#supplierId').val(receipt.supplier_id);
        $('#warehouseId').val(receipt.warehouse_id);
        $('#receiptNotes').val(receipt.notes);
        
        // Populate items
        this.populateItems(items);
        
        // Cập nhật UI labels
        $('#receiptCodeDisplay').text(receipt.receipt_code || 'Chưa có mã');
        $('#statusDisplay').html(`<span class="badge badge-${this.getStatusColor(receipt.status)}">${this.getStatusLabel(receipt.status)}</span>`);
    }

    populateItems(items) {
        const container = $('#receiptItemsList');
        container.empty();
        
        items.forEach((item, index) => {
            const itemHtml = this.createItemRow(item, index);
            container.append(itemHtml);
        });
        
        this.updateTotals();
    }

    createItemRow(item, index) {
        return `
            <div class="receipt-item border rounded p-3 mb-3" data-index="${index}">
                <div class="row">
                    <div class="col-md-4">
                        <label>Sản phẩm</label>
                        <input type="text" class="form-control" name="items[${index}][product_name]" 
                               value="${item.product_name || ''}" readonly>
                        <input type="hidden" name="items[${index}][product_id]" value="${item.product_id || ''}">
                        <input type="hidden" name="items[${index}][variant_id]" value="${item.variant_id || ''}">
                    </div>
                    <div class="col-md-2">
                        <label>Biến thể</label>
                        <input type="text" class="form-control" value="${item.variant_name || '-'}" readonly>
                    </div>
                    <div class="col-md-2">
                        <label>Số lượng</label>
                        <input type="number" class="form-control item-quantity" name="items[${index}][quantity]" 
                               value="${item.quantity || 1}" min="1" onchange="stockReceiptManager.updateItemTotal(${index})">
                    </div>
                    <div class="col-md-2">
                        <label>Giá nhập</label>
                        <input type="number" class="form-control item-price" name="items[${index}][unit_price]" 
                               value="${item.unit_price || 0}" min="0" onchange="stockReceiptManager.updateItemTotal(${index})">
                    </div>
                    <div class="col-md-2">
                        <label>Thành tiền</label>
                        <input type="text" class="form-control item-total" readonly value="${this.formatCurrency(item.total_price || 0)}">
                        <input type="hidden" name="items[${index}][total_price]" value="${item.total_price || 0}">
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-md-10">
                        <label>Ghi chú</label>
                        <input type="text" class="form-control" name="items[${index}][notes]" 
                               value="${item.notes || ''}" placeholder="Ghi chú cho sản phẩm này...">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="button" class="btn btn-danger btn-sm" onclick="stockReceiptManager.removeItem(${index})">
                            <i class="fas fa-trash"></i> Xóa
                        </button>
                    </div>
                </div>
                ${item.qr_code ? `
                    <div class="row mt-2">
                        <div class="col-12">
                            <div class="alert alert-info">
                                <i class="fas fa-qrcode mr-2"></i>
                                Sản phẩm đã có QR: <strong>${item.qr_code}</strong>
                                <button type="button" class="btn btn-sm btn-outline-info ml-2" onclick="stockReceiptManager.reprintQR('${item.qr_code}')">
                                    <i class="fas fa-print"></i> In lại QR
                                </button>
                            </div>
                        </div>
                    </div>
                ` : ''}
            </div>
        `;
    }

    async analyzeImage() {
        const fileInput = document.getElementById('productImages');
        const files = fileInput.files;
        
        if (files.length === 0) {
            this.showAlert('warning', 'Vui lòng chọn ảnh để phân tích');
            return;
        }

        const analyzeBtn = $('#analyzeImageBtn');
        const originalText = analyzeBtn.html();
        analyzeBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Đang phân tích...');

        try {
            const formData = new FormData();
            for (let i = 0; i < files.length; i++) {
                formData.append('images[]', files[i]);
            }
            formData.append('action', 'analyze_images');

            const response = await fetch('api_ai_analyze.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                this.aiAnalysisResults = result.analysis;
                this.displayAnalysisResults(result.analysis);
                this.showAlert('success', 'Phân tích AI hoàn tất!');
            } else {
                this.showAlert('error', 'Lỗi phân tích AI: ' + result.message);
            }
        } catch (error) {
            this.showAlert('error', 'Có lỗi xảy ra khi phân tích ảnh');
        } finally {
            analyzeBtn.prop('disabled', false).html(originalText);
        }
    }

    displayAnalysisResults(analysis) {
        const container = $('#aiAnalysisResults');
        let html = '<h5>Kết quả phân tích AI:</h5>';

        analysis.forEach((item, index) => {
            const isExisting = item.exists_in_db;
            const confidence = Math.round(item.confidence * 100);
            
            html += `
                <div class="analysis-item border rounded p-3 mb-3 ${isExisting ? 'border-success' : 'border-warning'}">
                    <div class="row">
                        <div class="col-md-3">
                            <img src="${item.processed_image}" class="img-fluid rounded" alt="Processed image">
                        </div>
                        <div class="col-md-9">
                            <h6>${item.product_name} <span class="badge badge-${isExisting ? 'success' : 'warning'}">${confidence}% chính xác</span></h6>
                            <p><strong>Thương hiệu:</strong> ${item.brand || 'Không xác định'}</p>
                            <p><strong>Màu sắc:</strong> ${item.color || 'Không xác định'}</p>
                            <p><strong>Size:</strong> ${item.size || 'Không xác định'}</p>
                            
                            ${isExisting ? `
                                <div class="alert alert-success">
                                    <i class="fas fa-check-circle mr-2"></i>
                                    Sản phẩm đã có trong hệ thống (ID: ${item.product_id})
                                    ${item.qr_code ? `<br>QR Code: <strong>${item.qr_code}</strong>` : ''}
                                </div>
                                <button type="button" class="btn btn-success btn-sm" onclick="stockReceiptManager.addExistingProduct(${index})">
                                    <i class="fas fa-plus"></i> Thêm vào phiếu nhập
                                </button>
                                ${item.qr_code ? `
                                    <button type="button" class="btn btn-info btn-sm ml-2" onclick="stockReceiptManager.reprintQR('${item.qr_code}')">
                                        <i class="fas fa-print"></i> In lại QR
                                    </button>
                                ` : ''}
                            ` : `
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle mr-2"></i>
                                    Sản phẩm chưa có trong hệ thống. Cần thêm mới.
                                </div>
                                <button type="button" class="btn btn-warning btn-sm" onclick="stockReceiptManager.addNewProduct(${index})">
                                    <i class="fas fa-plus"></i> Thêm sản phẩm mới
                                </button>
                            `}
                        </div>
                    </div>
                </div>
            `;
        });

        container.html(html).show();
    }

    addExistingProduct(analysisIndex) {
        const analysis = this.aiAnalysisResults[analysisIndex];
        const itemIndex = $('.receipt-item').length;
        
        const item = {
            product_id: analysis.product_id,
            variant_id: analysis.variant_id,
            product_name: analysis.product_name,
            variant_name: analysis.variant_name,
            quantity: 1,
            unit_price: analysis.suggested_price || 0,
            total_price: analysis.suggested_price || 0,
            qr_code: analysis.qr_code,
            notes: `Thêm từ AI Analysis (${Math.round(analysis.confidence * 100)}% confidence)`
        };

        const itemHtml = this.createItemRow(item, itemIndex);
        $('#receiptItemsList').append(itemHtml);
        this.updateTotals();
        
        this.showAlert('success', 'Đã thêm sản phẩm vào phiếu nhập');
    }

    addNewProduct(analysisIndex) {
        const analysis = this.aiAnalysisResults[analysisIndex];
        
        // Chuyển hướng to add product page with pre-filled data
        const params = new URLSearchParams({
            from_receipt: 'true',
            name: analysis.product_name,
            brand: analysis.brand || '',
            color: analysis.color || '',
            size: analysis.size || '',
            image: analysis.processed_image
        });
        
        window.location.href = `them_san_pham_ai.php?${params.toString()}`;
    }

    async saveDraft() {
        const formData = this.gatherFormData();
        
        try {
            const response = await this.apiCall('save_draft', formData);
            
            if (response.success) {
                this.currentDraft = { 
                    receipt_id: response.receipt_id, 
                    receipt_code: response.receipt_code 
                };
                this.showAlert('success', 'Đã lưu phiếu nháp thành công!');
                this.updateUIAfterSave();
            } else {
                this.showAlert('error', 'Lỗi khi lưu: ' + response.message);
            }
        } catch (error) {
            this.showAlert('error', 'Có lỗi xảy ra khi lưu phiếu nháp');
        }
    }

    async updateDraft() {
        if (!this.currentDraft || !this.currentDraft.receipt_id) {
            this.showAlert('warning', 'Chưa có phiếu nháp để cập nhật');
            return;
        }

        const formData = this.gatherFormData();
        formData.receipt_id = this.currentDraft.receipt_id;
        formData.change_reason = prompt('Lý do thay đổi (tùy chọn):') || '';

        try {
            const response = await this.apiCall('update_draft', formData);
            
            if (response.success) {
                this.showAlert('success', `Đã cập nhật phiếu nháp! Thay đổi: ${response.changes.join(', ')}`);
                this.updateUIAfterSave();
            } else {
                this.showAlert('error', 'Lỗi khi cập nhật: ' + response.message);
            }
        } catch (error) {
            this.showAlert('error', 'Có lỗi xảy ra khi cập nhật phiếu nháp');
        }
    }

    async confirmReceipt() {
        if (!this.currentDraft || !this.currentDraft.receipt_id) {
            this.showAlert('warning', 'Cần lưu phiếu nháp trước khi xác nhận');
            return;
        }

        if (!confirm('Bạn có chắc chắn muốn xác nhận phiếu nhập này? Sau khi xác nhận sẽ không thể chỉnh sửa và tồn kho sẽ được cập nhật.')) {
            return;
        }

        const confirmBtn = $('#confirmReceiptBtn');
        const originalText = confirmBtn.html();
        confirmBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Đang xác nhận...');

        try {
            const response = await this.apiCall('confirm_receipt', { 
                receipt_id: this.currentDraft.receipt_id 
            });
            
            if (response.success) {
                this.showAlert('success', 'Đã xác nhận phiếu nhập thành công!');
                this.displayQRCodes(response.qr_codes);
                this.updateUIAfterConfirm();
            } else {
                this.showAlert('error', 'Lỗi khi xác nhận: ' + response.message);
                confirmBtn.prop('disabled', false).html(originalText);
            }
        } catch (error) {
            this.showAlert('error', 'Có lỗi xảy ra khi xác nhận phiếu');
            confirmBtn.prop('disabled', false).html(originalText);
        }
    }

    displayQRCodes(qrCodes) {
        let html = '<h5>QR Codes được tạo/sử dụng:</h5><div class="row">';
        
        qrCodes.forEach(qr => {
            html += `
                <div class="col-md-3 mb-3">
                    <div class="card">
                        <div class="card-body text-center">
                            <h6>${qr.qr_code}</h6>
                            <p class="small text-muted">${qr.is_new ? 'Mới tạo' : 'Đã có sẵn'}</p>
                            <button class="btn btn-sm btn-primary" onclick="stockReceiptManager.printQR('${qr.qr_code}')">
                                <i class="fas fa-print"></i> In QR
                            </button>
                        </div>
                    </div>
                </div>
            `;
        });
        
        html += '</div>';
        $('#qrCodesDisplay').html(html).show();
    }

    gatherFormData() {
        const items = [];
        $('.receipt-item').each(function(index) {
            const $item = $(this);
            items.push({
                product_id: $item.find('input[name*="[product_id]"]').val(),
                variant_id: $item.find('input[name*="[variant_id]"]').val() || null,
                quantity: parseInt($item.find('.item-quantity').val()) || 1,
                unit_price: parseFloat($item.find('.item-price').val()) || 0,
                notes: $item.find('input[name*="[notes]"]').val() || ''
            });
        });

        return {
            supplier_id: $('#supplierId').val(),
            warehouse_id: $('#warehouseId').val(),
            notes: $('#receiptNotes').val(),
            items: items,
            ai_analysis_result: this.aiAnalysisResults
        };
    }

    async apiCall(action, data) {
        const response = await fetch('public/index.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ action, ...data })
        });

        return await response.json();
    }

    updateItemTotal(index) {
        const $item = $(`.receipt-item[data-index="${index}"]`);
        const quantity = parseFloat($item.find('.item-quantity').val()) || 0;
        const price = parseFloat($item.find('.item-price').val()) || 0;
        const total = quantity * price;
        
        $item.find('.item-total').val(this.formatCurrency(total));
        $item.find('input[name*="[total_price]"]').val(total);
        
        this.updateTotals();
    }

    updateTotals() {
        let grandTotal = 0;
        $('.receipt-item').each(function() {
            const total = parseFloat($(this).find('input[name*="[total_price]"]').val()) || 0;
            grandTotal += total;
        });
        
        $('#grandTotal').text(this.formatCurrency(grandTotal));
    }

    removeItem(index) {
        $(`.receipt-item[data-index="${index}"]`).remove();
        this.updateTotals();
    }

    autoSave() {
        if (this.isEditing && this.currentDraft && this.currentDraft.receipt_id) {
            // Auto-save silently for drafts
            this.updateDraft();
        }
    }

    updateUIForEditMode() {
        $('#saveDraftBtn').hide();
        $('#updateDraftBtn, #confirmReceiptBtn').show();
        $('.page-title').text('Chỉnh sửa phiếu nhập');
    }

    updateUIAfterSave() {
        $('#saveDraftBtn').hide();
        $('#updateDraftBtn, #confirmReceiptBtn').show();
        this.isEditing = true;
    }

    updateUIAfterConfirm() {
        $('#updateDraftBtn, #confirmReceiptBtn').hide();
        $('.form-control').prop('disabled', true);
        $('.page-title').text('Phiếu nhập đã xác nhận');
    }

    formatCurrency(amount) {
        return new Intl.NumberFormat('vi-VN').format(amount) + ' VNĐ';
    }

    getStatusLabel(status) {
        const labels = {
            'draft': 'Bản nháp',
            'pending': 'Chờ xác nhận', 
            'confirmed': 'Đã xác nhận',
            'rejected': 'Đã từ chối'
        };
        return labels[status] || status;
    }

    getStatusColor(status) {
        const colors = {
            'draft': 'warning',
            'pending': 'info',
            'confirmed': 'success', 
            'rejected': 'danger'
        };
        return colors[status] || 'secondary';
    }

    showAlert(type, message) {
        const alertClass = type === 'error' ? 'danger' : type;
        const alertHtml = `
            <div class="alert alert-${alertClass} alert-dismissible fade show" role="alert">
                ${message}
                <button type="button" class="close" data-dismiss="alert">
                    <span>&times;</span>
                </button>
            </div>
        `;
        
        $('#alertContainer').html(alertHtml);
        
        // Auto-hide after 5 seconds
        setTimeout(() => {
            $('.alert').fadeOut();
        }, 5000);
    }

    async reprintQR(qrCode) {
        try {
            const response = await fetch('api_quan_ly_ma_qr.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'reprint', qr_code: qrCode })
            });
            
            const result = await response.json();
            if (result.success) {
                window.open(result.print_url, '_blank');
            } else {
                this.showAlert('error', 'Không thể in QR: ' + result.message);
            }
        } catch (error) {
            this.showAlert('error', 'Có lỗi xảy ra khi in QR');
        }
    }

    async printQR(qrCode) {
        this.reprintQR(qrCode);
    }
}

// Khởi tạo when document is ready
$(document).ready(function() {
    window.stockReceiptManager = new StockReceiptManager();
});
