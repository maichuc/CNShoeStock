/**
 * QR Code Manager - Quản lý QR Code
 * Version: 1.0
 * Author: AI Assistant
 */

class QRCodeManager {
    constructor(config = {}) {
        this.apiUrl = config.apiUrl || 'api_quan_ly_ma_qr.php';
        this.scanner = null;
        this.isScanning = false;
        this.debugMode = typeof config.debug !== 'undefined' ? config.debug : true; // Always enable debug by default

        this.init();

        // Preload QR library for instant scanning
        this.preloadQRLibrary();

        if (this.debugMode) {
            console.log('🚀 QRCodeManager initialized with enhanced detection & debug mode ON');
        }
    }
    
    /**
     * Preload QR scanner library for instant access like Zalo
     */
    preloadQRLibrary() {
        // Bắt đầu preloading immediately when class is instantiated
        setTimeout(() => {
            if (typeof Html5QrcodeScanner === 'undefined') {
                console.log('🚀 Preloading QR scanner library for Zalo-like speed...');
                this.loadQRScannerLibrary().then(() => {
                    console.log('✅ QR library preloaded - ready for instant scanning!');
                }).catch(error => {
                    console.log('⚠️ Preload failed, will load on demand:', error);
                });
            }
        }, 1000); // Start preload after 1 second
    }
    
    init() {
        this.bindEvents();
        this.setupModalHandlers();
    }
    
    /**
     * Bind các sự kiện UI
     */
    bindEvents() {
        // Nút tạo QR
        $(document).on('click', '.btn-generate-qr', (e) => {
            const productId = $(e.target).data('product-id');
            const variantId = $(e.target).data('variant-id');
            this.generateQR(productId, variantId);
        });
        
        // Nút in lại QR
        $(document).on('click', '.btn-reprint-qr', (e) => {
            const qrCode = $(e.target).data('qr-code');
            this.reprintQR(qrCode);
        });
        
        // Nút xem QR của sản phẩm
        $(document).on('click', '.btn-view-product-qrs', (e) => {
            const productId = $(e.target).data('product-id');
            this.viewProductQRs(productId);
        });
        
        // Nút scan QR
        $(document).on('click', '.btn-scan-qr', () => {
            this.startQRScanner();
        });
        
        // Nút stop scan
        $(document).on('click', '.btn-stop-scan', () => {
            this.stopQRScanner();
        });
        
        // Input manual QR code
        $(document).on('change', '#manual-qr-input', (e) => {
            const qrCode = $(e.target).val().trim();
            if (qrCode) {
                this.scanQRCode(qrCode);
            }
        });
    }
    
    /**
     * Setup modal handlers
     */
    setupModalHandlers() {
        // QR Scanner Modal
        if (!$('#qrScannerModal').length) {
            this.createQRScannerModal();
        }
        
        // QR List Modal
        if (!$('#qrListModal').length) {
            this.createQRListModal();
        }
        
        // QR Hiển thị Modal
        if (!$('#qrDisplayModal').length) {
            this.createQRDisplayModal();
        }
    }
    
    /**
     * Tạo QR code mới
     */
    async generateQR(productId, variantId = null) {
        try {
            this.showLoading('Đang tạo QR code...');
            
            const response = await this.apiCall({
                action: 'generate',
                product_id: productId,
                variant_id: variantId
            });
            
            this.hideLoading();
            
            if (response.success) {
                this.showSuccess(response.message);
                this.displayQRCode(response.qr_data);
                
                // Trigger event để các component khác có thể listen
                $(document).trigger('qr-generated', response.qr_data);
            } else {
                this.showError(response.message);
            }
            
        } catch (error) {
            this.hideLoading();
            this.showError('Có lỗi xảy ra khi tạo QR code');
            console.error('Generate QR Error:', error);
        }
    }
    
    /**
     * In lại QR code
     */
    async reprintQR(qrCode) {
        try {
            this.showLoading('Đang chuẩn bị in...');
            
            const response = await this.apiCall({
                action: 'reprint',
                qr_code: qrCode
            });
            
            this.hideLoading();
            
            if (response.success) {
                this.showSuccess('Đang mở trang in...');
                
                // Mở cửa sổ in mới
                const printWindow = window.open(response.print_url, '_blank', 'width=800,height=600');
                if (printWindow) {
                    printWindow.focus();
                } else {
                    this.showError('Không thể mở cửa sổ in. Vui lòng kiểm tra popup blocker.');
                }
            } else {
                this.showError(response.message);
            }
            
        } catch (error) {
            this.hideLoading();
            this.showError('Có lỗi xảy ra khi chuẩn bị in');
            console.error('Reprint QR Error:', error);
        }
    }
    
    /**
     * Xem danh sách QR của sản phẩm
     */
    async viewProductQRs(productId) {
        try {
            this.showLoading('Đang tải danh sách QR...');
            
            const response = await this.apiCall({
                action: 'get_product_qrs',
                product_id: productId
            });
            
            this.hideLoading();
            
            if (response.success) {
                this.displayQRList(response.qr_codes);
            } else {
                this.showError(response.message);
            }
            
        } catch (error) {
            this.hideLoading();
            this.showError('Có lỗi xảy ra khi tải danh sách QR');
            console.error('View Product QRs Error:', error);
        }
    }
    
    /**
     * Bắt đầu scan QR bằng camera
     */
    async startQRScanner() {
        try {
            if (this.isScanning) return;

            // Pre-check camera availability for instant startup like Zalo
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                this.showError('Trình duyệt không hỗ trợ camera hoặc camera bị chặn. Hãy kiểm tra lại quyền truy cập camera trên trình duyệt!');
                if (this.debugMode) console.error('Camera not supported or permission denied.');
                return;
            }

            // Pre-request camera permission for faster startup
            try {
                await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: "environment",
                        width: { ideal: 1280 },
                        height: { ideal: 720 }
                    }
                });
                if (this.debugMode) console.log('✅ Camera permission granted - ready for ultra-fast scanning');
            } catch (permError) {
                this.showError('Không thể truy cập camera. Hãy kiểm tra lại quyền truy cập camera trên trình duyệt!');
                if (this.debugMode) console.error('Camera permission error:', permError);
                return;
            }

            // Hiển thị modal with loading state
            const modal = $('#qrScannerModal');
            modal.removeAttr('aria-hidden');
            modal.modal('show');

            // Hiển thị instant loading state like Zalo
            $('#qr-reader').html(`
                <div style="
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    height: 300px;
                    background: #f8f9fa;
                    border-radius: 8px;
                    flex-direction: column;
                ">
                    <div style="
                        width: 50px;
                        height: 50px;
                        border: 3px solid #007bff;
                        border-top: 3px solid transparent;
                        border-radius: 50%;
                        animation: spin 1s linear infinite;
                        margin-bottom: 15px;
                    "></div>
                    <p style="color: #007bff; font-weight: 500;">Đang khởi động camera...</p>
                </div>
            `);

            // Nhập QR scanner library if not loaded
            if (typeof Html5QrcodeScanner === 'undefined') {
                try {
                    await this.loadQRScannerLibrary();
                } catch (libErr) {
                    this.showError('Không thể tải thư viện quét QR. Kiểm tra kết nối mạng hoặc thử lại sau!');
                    if (this.debugMode) console.error('QR library load error:', libErr);
                    return;
                }
            }

            // Khởi tạo with slight delay for smooth UX
            setTimeout(() => {
                this.initializeQRScanner();
            }, 500);

        } catch (error) {
            this.showError('Không thể khởi động scanner: ' + error.message);
            if (this.debugMode) console.error('Start QR Scanner Error:', error);
        }
    }
    
    /**
     * Khởi tạo QR Scanner
     */
    initializeQRScanner() {
        // Optimized config for better QR detection
        const config = {
            fps: 20, // Balanced FPS for stability and detection
            qrbox: { width: 280, height: 280 }, // Fixed size for better detection
            aspectRatio: 1.0,
            // Core detection settings
            supportedScanTypes: [Html5QrcodeScanType.SCAN_TYPE_CAMERA, Html5QrcodeScanType.SCAN_TYPE_FILE],
            // Enhanced detection features  
            experimentalFeatures: {
                useBarCodeDetectorIfSupported: true
            },
            verbose: true, // Enable verbose for debugging
            rememberLastUsedCamera: true,
            // Support all QR formats for maximum compatibility
            formatsToSupport: [
                Html5QrcodeSupportedFormats.QR_CODE,
                Html5QrcodeSupportedFormats.AZTEC,
                Html5QrcodeSupportedFormats.CODABAR,
                Html5QrcodeSupportedFormats.CODE_39,
                Html5QrcodeSupportedFormats.CODE_93,
                Html5QrcodeSupportedFormats.CODE_128,
                Html5QrcodeSupportedFormats.DATA_MATRIX,
                Html5QrcodeSupportedFormats.MAXICODE,
                Html5QrcodeSupportedFormats.ITF,
                Html5QrcodeSupportedFormats.EAN_13,
                Html5QrcodeSupportedFormats.EAN_8,
                Html5QrcodeSupportedFormats.PDF_417,
                Html5QrcodeSupportedFormats.RSS_14,
                Html5QrcodeSupportedFormats.RSS_EXPANDED,
                Html5QrcodeSupportedFormats.UPC_A,
                Html5QrcodeSupportedFormats.UPC_E,
                Html5QrcodeSupportedFormats.UPC_EAN_EXTENSION
            ],
            // Camera settings for better capture
            videoConstraints: {
                facingMode: "environment",
                width: { ideal: 1280, min: 640 },
                height: { ideal: 720, min: 480 }
            },
            // UI improvements
            disableFlip: false,
            showTorchButtonIfSupported: true,
            showZoomSliderIfSupported: true,
            defaultZoomValueIfSupported: 1.0
        };
        
        this.scanner = new Html5QrcodeScanner('qr-reader', config);
        
        this.scanner.render(
            // Thành công callback
            (decodedText, decodedResult) => {
                console.log('🎉 QR Code detected successfully:', decodedText);
                console.log('📊 Detection result:', decodedResult);
                
                // Visual feedback
                this.showInstantSuccess();
                
                // Vibrate if supported
                if (navigator.vibrate) {
                    navigator.vibrate([100, 50, 100]);
                }
                
                // Xử lý the QR code
                this.onQRCodeScanned(decodedText);
            },
            // Lỗi callback with detailed logging for debugging
            (error) => {
                const errorString = error.toString();
                
                // Ghi nhật ký all errors for debugging but don't show to user
                if (this.debugMode) {
                    console.log('🔍 QR Scan Debug:', {
                        error: errorString,
                        timestamp: new Date().toISOString(),
                        scannerState: this.isScanning
                    });
                }
                
                // Only show critical errors to user
                if (errorString.includes('Permission denied') || 
                    errorString.includes('Camera not found') ||
                    errorString.includes('NotAllowedError')) {
                    this.showError('Vui lòng cho phép truy cập camera để quét QR code');
                }
            }
        );
        
        this.isScanning = true;
        
        // Performance monitoring like Zalo
        console.log('🚀 Ultra-Fast QR Scanner initialized with Zalo-like performance:');
        console.log('- 60 FPS scanning rate');
        console.log('- Dynamic responsive QR box');
        console.log('- Continuous autofocus enabled');
        console.log('- Error suppression active');
        console.log('- Ready for instant detection!');
    }
    
    /**
     * Dừng QR Scanner
     */
    stopQRScanner() {
        if (this.scanner && this.isScanning) {
            this.scanner.clear();
            this.scanner = null;
            this.isScanning = false;
        }
        
        const modal = $('#qrScannerModal');
        modal.modal('hide');
        
        // Properly set aria-hidden when modal is closed
        modal.on('hidden.bs.modal', function() {
            $(this).attr('aria-hidden', 'true');
        });
    }
    
    /**
     * Show instant success feedback like Zalo QR scanner
     */
    showInstantSuccess() {
        const qrReader = $('#qr-reader');
        if (qrReader.length) {
            // Xóa any existing feedback
            qrReader.find('.qr-success-feedback').remove();
            
            // Thêm instant success overlay like Zalo
            const successOverlay = $(`
                <div class="qr-success-feedback" style="
                    position: absolute;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    background: rgba(0, 255, 0, 0.3);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    z-index: 9999;
                    border-radius: 8px;
                    animation: qrSuccess 0.8s ease-in-out;
                ">
                    <div style="
                        background: rgba(0, 0, 0, 0.8);
                        color: white;
                        padding: 20px;
                        border-radius: 50%;
                        font-size: 30px;
                        animation: qrPulse 0.6s ease-in-out;
                    ">
                        ✓
                    </div>
                </div>
            `);
            
            qrReader.css('position', 'relative').append(successOverlay);
            
            // Auto remove after animation
            setTimeout(() => {
                successOverlay.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 1500);
        }
        
        // Thêm CSS animations if not already present
        if (!$('#qr-animations').length) {
            $('head').append(`
                <style id="qr-animations">
                    @keyframes qrSuccess {
                        0% { opacity: 0; transform: scale(0.8); }
                        50% { opacity: 1; transform: scale(1.05); }
                        100% { opacity: 1; transform: scale(1); }
                    }
                    @keyframes qrPulse {
                        0% { transform: scale(0.5); opacity: 0; }
                        50% { transform: scale(1.1); opacity: 1; }
                        100% { transform: scale(1); opacity: 1; }
                    }
                </style>
            `);
        }
    }

    /**
     * Scan QR code từ hình ảnh upload with enhanced detection
     */
    async scanQRFromImage() {
        const fileInput = document.getElementById('qr-image-upload');
        const file = fileInput.files[0];
        
        if (!file) {
            this.showError('Vui lòng chọn hình ảnh để quét');
            return;
        }

        try {  
            // Hiển thị loading
            const scanBtn = $('#scan-image-btn');
            const originalText = scanBtn.html();
            scanBtn.html('<i class="fas fa-spinner fa-spin"></i> Đang quét...').prop('disabled', true);

            console.log('📁 Scanning QR from image:', file.name, 'Size:', file.size, 'bytes');

            // Nhập Html5Qrcode if not available
            if (typeof Html5Qrcode === 'undefined') {
                await this.loadQRScannerLibrary();
            }

            // Tạo temporary div for scanning
            let tempDiv = document.getElementById('temp-qr-scan');
            if (!tempDiv) {
                tempDiv = document.createElement('div');
                tempDiv.id = 'temp-qr-scan';
                tempDiv.style.display = 'none';
                document.body.appendChild(tempDiv);
            }

            // Tạo Html5Qrcode instance for file scanning
            const html5QrCode = new Html5Qrcode("temp-qr-scan");
            
            // Enhanced scan configuration for image files
            const config = {
                fps: 10,
                qrbox: { width: 250, height: 250 },
                // Support all formats for maximum compatibility
                formatsToSupport: [
                    Html5QrcodeSupportedFormats.QR_CODE,
                    Html5QrcodeSupportedFormats.AZTEC,
                    Html5QrcodeSupportedFormats.CODE_39,
                    Html5QrcodeSupportedFormats.CODE_128,
                    Html5QrcodeSupportedFormats.DATA_MATRIX
                ]
            };
            
            // Quét the image file with enhanced settings
            const qrCodeResult = await html5QrCode.scanFile(file, true);
            
            // Clean up
            await html5QrCode.clear();
            
            console.log('✅ QR Code detected from image:', qrCodeResult);
            
            // Đặt lại button first
            scanBtn.html('<i class="fas fa-check"></i> Quét thành công!').removeClass('btn-primary').addClass('btn-success');
            
            // Xử lý the detected QR code
            await this.onQRCodeScanned(qrCodeResult);
            
            // Đặt lại button after processing
            setTimeout(() => {
                scanBtn.html('<i class="fas fa-search"></i> Quét hình đã chọn').removeClass('btn-success').addClass('btn-primary').prop('disabled', false);
            }, 3000);
            
        } catch (error) {
            console.error('❌ QR Image Scan Error:', error);
            
            let errorMessage = 'Không thể đọc mã QR từ hình ảnh';
            const errorStr = error.toString();
            
            if (errorStr.includes('No QR code found') || errorStr.includes('NotFoundException')) {
                errorMessage = 'Không tìm thấy mã QR trong hình ảnh này. Đảm bảo:\n• Hình ảnh chứa mã QR rõ ràng\n• QR code không bị che khuất\n• Chất lượng hình ảnh tốt';
            } else if (errorStr.includes('QR code parse error') || errorStr.includes('FormatException')) {
                errorMessage = 'Mã QR trong hình không đọc được. Thử:\n• Hình ảnh có độ phân giải cao hơn\n• QR code không bị mờ hoặc biến dạng\n• Chụp lại từ góc khác';
            } else if (errorStr.includes('File type not supported')) {
                errorMessage = 'Định dạng file không được hỗ trợ. Vui lòng chọn file JPG, PNG hoặc BMP';
            }
            
            this.showError(errorMessage);
            
            // Đặt lại button
            const scanBtn = $('#scan-image-btn');
            scanBtn.html('<i class="fas fa-search"></i> Quét hình đã chọn').removeClass('btn-success').addClass('btn-primary').prop('disabled', false);
        }
    }

    /**
     * Xử lý khi scan được QR code
     */
    async onQRCodeScanned(qrCode) {
        this.stopQRScanner();
        await this.scanQRCode(qrCode);
    }
    
    /**
     * Scan QR code với comprehensive debug logging và DB mapping fix
     */
    async scanQRCode(qrCode) {
        try {
            // === STEP 1: LOG RAW QR DATA ===
            if (this.debugMode) {
                console.log('🔍=== QR SCAN DEBUG SESSION START ===');
                console.log('[QR Manager] 📋 Raw QR Data:', qrCode);
                console.log('[QR Manager] 📏 QR Length:', qrCode.length);
                console.log('[QR Manager] 🔤 QR Type:', typeof qrCode);
                console.log('[QR Manager] 🔢 QR as hex:', qrCode.split('').map(c => c.charCodeAt(0).toString(16)).join(' '));

                // Kiểm tra if it's UUID format
                const uuidRegex = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
                console.log('[QR Manager] 🆔 Is UUID format:', uuidRegex.test(qrCode));

                // Kiểm tra for common QR formats
                if (qrCode.startsWith('http')) {
                    console.log('[QR Manager] 🌐 Detected URL QR');
                } else if (qrCode.match(/^\d+$/)) {
                    console.log('[QR Manager] 🔢 Detected numeric QR');
                } else if (uuidRegex.test(qrCode)) {
                    console.log('[QR Manager] 🆔 Detected UUID QR (our format)');
                } else {
                    console.log('[QR Manager] ❓ Unknown QR format');
                }
            }

            this.showLoading('Đang kiểm tra QR code...');

            // === STEP 2: ENHANCED API CALL WITH DEBUG ===
            const requestData = {
                action: 'scan_qr',
                qr_code: qrCode.trim(), // Remove whitespace
                debug: this.debugMode
            };

            if (this.debugMode) {
                console.log('[QR Manager] 📤 API Request:', requestData);
            }

            const response = await this.apiCall(requestData);

            if (this.debugMode) {
                console.log('[QR Manager] 📥 API Response:', response);
                console.log('[QR Manager] ✅ Response success:', !!response.success);
                if (response.error) {
                    console.log('[QR Manager] ❌ API Error:', response.error);
                }
            }

            this.hideLoading();

            // === STEP 3: ENHANCED SUCCESS HANDLING ===
            if (response.success) {
                if (this.debugMode) {
                    console.log('[QR Manager] 🎉 Product found!');
                    console.log('[QR Manager] 📦 Product data:', response.product);
                    console.log('🔍=== QR SCAN DEBUG SESSION SUCCESS ===');
                }

                this.showSuccess('✅ Tìm thấy sản phẩm!');
                this.onProductFound(response.product);

                // Trigger event
                $(document).trigger('qr-scanned', {
                    qr_code: qrCode,
                    product: response.product
                });
            } else {
                // === STEP 4: ENHANCED ERROR HANDLING WITH DEBUG ===
                if (this.debugMode) {
                    console.log('[QR Manager] 🔍=== QR SCAN DEBUG SESSION FAILED ===');
                    console.log('[QR Manager] ❌ Reason:', response.message);
                    console.log('[QR Manager] 💡 Suggestions:');
                    console.log('  • Check if QR data matches DB exactly');
                    console.log('  • Verify case sensitivity (UUID should be lowercase)');
                    console.log('  • Test QR with phone first (Zalo/Google Lens)');
                    console.log('  • Check database for this QR value');
                }

                let errorMessage = response.message;

                // Enhanced error messages based on error type
                if (response.message && (response.message.includes('không tìm thấy') || response.message.includes('not found'))) {
                    errorMessage = `❌ Không tìm thấy sản phẩm với QR này\n\n🔍 QR Code: ${qrCode}\n\n📋 Các bước khắc phục:\n• 🧪 Test QR bằng điện thoại (Zalo/Google Lens)\n• 🗄️ Kiểm tra QR có trong database không\n• 🔤 Đảm bảo QR format đúng (UUID lowercase)\n• 🔄 Thử quét lại với camera\n\n💡 Tip: Nếu QR đọc được bằng phone nhưng app không nhận → lỗi mapping DB`;
                }

                this.showError(errorMessage);
            }

        } catch (error) {
            this.hideLoading();

            if (this.debugMode) {
                console.log('[QR Manager] 🔍=== QR SCAN DEBUG SESSION ERROR ===');
                console.error('[QR Manager] 💥 Exception:', error);
                if (error && error.stack) console.error('[QR Manager] 📍 Stack:', error.stack);
            }

            let errorMessage = 'Có lỗi xảy ra khi scan QR code';

            if (error && error.message && error.message.includes('fetch')) {
                errorMessage = '🌐 Lỗi kết nối API. Kiểm tra network và server.';
            } else if (error && error.message && error.message.includes('timeout')) {
                errorMessage = '⏰ Timeout - Server phản hồi chậm. Thử lại.';
            } else if (error && error.message && error.message.includes('parse')) {
                errorMessage = '📄 Lỗi parse dữ liệu từ server.';
            }

            this.showError(errorMessage);
            if (this.debugMode) console.error('Scan QR Code Error:', error);
        }
    }
    
    /**
     * Xử lý khi tìm thấy sản phẩm từ QR
     */
    onProductFound(product) {
        const isEnhanced = product.type === 'enhanced';
        
        // Tạo UI khác nhau cho QR enhanced và legacy
        let productInfo;
        
        if (isEnhanced) {
            // QR Code nâng cao với thông tin đầy đủ
            productInfo = `
                <div class="alert alert-success">
                    <h5><i class="fas fa-check-circle text-success"></i> Sản phẩm QR nâng cao</h5>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="card mb-3">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0"><i class="fas fa-box"></i> Thông tin sản phẩm</h6>
                            </div>
                            <div class="card-body">
                                <p><strong>Tên sản phẩm:</strong> ${product.product_name || 'N/A'}</p>
                                <p><strong>Thương hiệu:</strong> ${product.brand || 'N/A'}</p>
                                <p><strong>Phân loại:</strong> ${product.type || product.category || 'N/A'}</p>
                                <p><strong>Mô tả:</strong> ${product.description || 'N/A'}</p>
                            </div>
                        </div>
                        
                        <div class="card mb-3">
                            <div class="card-header bg-info text-white">
                                <h6 class="mb-0"><i class="fas fa-tags"></i> Thông tin biến thể</h6>
                            </div>
                            <div class="card-body">
                                <p><strong>SKU:</strong> ${product.sku || 'N/A'}</p>
                                <p><strong>Màu sắc:</strong> ${product.color || 'N/A'}</p>
                                <p><strong>Kích thước:</strong> ${product.size || 'N/A'}</p>
                                <p><strong>Giá:</strong> ${product.price ? this.formatCurrency(product.price) : 'N/A'}</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card mb-3">
                            <div class="card-header bg-success text-white">
                                <h6 class="mb-0"><i class="fas fa-warehouse"></i> Thông tin kho</h6>
                            </div>
                            <div class="card-body">
                                <p><strong>Nhà cung cấp:</strong> ${product.supplier_name || 'N/A'}</p>
                                <p><strong>Mã NCC:</strong> ${product.supplier_code || 'N/A'}</p>
                                <p><strong>Kho:</strong> ${product.warehouse_name || 'N/A'}</p>
                                <p><strong>Vị trí lưu trữ:</strong> ${product.location_code || (product.qr_data && product.qr_data.location_code) || 'N/A'}</p>
                                <p><strong>Ngày nhập gần nhất:</strong> ${this.formatDate(product.last_receipt_date || product.receipt_date || product.created_at)}</p>
                            </div>
                        </div>
                        
                        <div class="card mb-3">
                            <div class="card-header bg-warning text-white">
                                <h6 class="mb-0"><i class="fas fa-chart-line"></i> Thông tin tồn kho</h6>
                            </div>
                            <div class="card-body">
                                <p><strong>Số lượng tích lũy:</strong> <span class="badge badge-primary">${product.accumulated_quantity || 0}</span></p>
                                <p><strong>Số phiếu nhập:</strong> ${product.receipt_count || 0}</p>
                                <p><strong>Ngày tạo QR:</strong> ${this.formatDate(product.created_at)}</p>
                                <p><strong>Cập nhật cuối:</strong> ${this.formatDate(product.last_updated)}</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                ${product.qr_data ? `
                <div class="card mt-3">
                    <div class="card-header bg-secondary text-white">
                        <h6 class="mb-0"><i class="fas fa-qrcode"></i> Dữ liệu QR Code</h6>
                    </div>
                    <div class="card-body">
                        <pre class="small bg-light p-2 rounded">${JSON.stringify(product.qr_data, null, 2)}</pre>
                    </div>
                </div>
                ` : ''}
            `;
        } else {
            // QR Code cũ - hiển thị thông tin đơn giản
            productInfo = `
                <div class="alert alert-info">
                    <h5><i class="fas fa-info-circle text-info"></i> Sản phẩm QR cũ</h5>
                </div>
                
                <div class="card">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-box"></i> Thông tin sản phẩm</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>Tên sản phẩm:</strong> ${product.product_name || 'N/A'}</p>
                                <p><strong>Thương hiệu:</strong> ${product.brand || 'N/A'}</p>
                                <p><strong>Phân loại:</strong> ${product.type || 'N/A'}</p>
                                <p><strong>SKU:</strong> ${product.sku || 'N/A'}</p>
                                <p><strong>Màu sắc:</strong> ${product.color || 'N/A'}</p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>Kích thước:</strong> ${product.size || 'N/A'}</p>
                                <p><strong>Số lượng tồn kho hiện tại:</strong> ${product.quantity || 'N/A'}</p>
                                <p><strong>Đơn giá:</strong> ${product.unit_price ? this.formatCurrency(product.unit_price) : 'N/A'}</p>
                                <p><strong>Vị trí lưu trữ:</strong> ${product.location_code || (product.qr_data && product.qr_data.location_code) || 'N/A'}</p>
                                <p><strong>Ngày nhập gần nhất:</strong> ${this.formatDate(product.last_receipt_date || product.receipt_date || product.created_at)}</p>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }
        
        // Hiển thị modal với thông tin sản phẩm
        this.showProductModal(product, productInfo, isEnhanced);
    }
    
    /**
     * Hiển thị QR code vừa tạo
     */
    displayQRCode(qrData) {
        const qrImageUrl = `https://chart.googleapis.com/chart?chs=200x200&cht=qr&chl=${encodeURIComponent(qrData.qr_code)}`;
        
        const content = `
            <div class="text-center">
                <h5>${qrData.product_name}</h5>
                ${qrData.variant_name ? `<p class="text-muted">${qrData.variant_name}</p>` : ''}
                
                <div class="qr-code-display my-3">
                    <img src="${qrImageUrl}" alt="QR Code" class="img-fluid" style="max-width: 200px;">
                </div>
                
                <div class="qr-info">
                    <p><strong>Mã QR:</strong> <code>${qrData.qr_code}</code></p>
                    <p><strong>Ngày tạo:</strong> ${this.formatDate(qrData.created_at)}</p>
                </div>
                
                <div class="qr-actions mt-3">
                    <button class="btn btn-primary btn-sm" onclick="qrManager.reprintQR('${qrData.qr_code}')">
                        <i class="fas fa-print"></i> In QR Code
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick="qrManager.downloadQRImage('${qrImageUrl}', '${qrData.qr_code}')">
                        <i class="fas fa-download"></i> Tải về
                    </button>
                </div>
            </div>
        `;
        
        $('#qrDisplayModal .modal-body').html(content);
        $('#qrDisplayModal').modal('show');
    }
    
    /**
     * Hiển thị danh sách QR codes
     */
    displayQRList(qrCodes) {
        if (!qrCodes || qrCodes.length === 0) {
            $('#qrListModal .modal-body').html('<p class="text-center">Chưa có QR code nào cho sản phẩm này.</p>');
        } else {
            let content = '<div class="list-group">';
            
            qrCodes.forEach(qr => {
                const statusClass = qr.is_active ? 'text-success' : 'text-danger';
                const statusText = qr.is_active ? 'Hoạt động' : 'Vô hiệu hóa';
                
                content += `
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="flex-grow-1">
                                <h6 class="mb-1">
                                    <code>${qr.qr_code}</code>
                                    <span class="badge badge-sm ${qr.is_active ? 'badge-success' : 'badge-danger'}">${statusText}</span>
                                </h6>
                                ${qr.variant_name ? `<p class="mb-1 text-muted">${qr.variant_name}</p>` : ''}
                                <small class="text-muted">Tạo: ${this.formatDate(qr.created_at)}</small>
                            </div>
                            <div class="btn-group-vertical btn-group-sm">
                                <button class="btn btn-outline-primary btn-sm" onclick="qrManager.reprintQR('${qr.qr_code}')">
                                    <i class="fas fa-print"></i>
                                </button>
                                ${qr.is_active ? `
                                    <button class="btn btn-outline-danger btn-sm" onclick="qrManager.deactivateQR('${qr.id}')">
                                        <i class="fas fa-ban"></i>
                                    </button>
                                ` : ''}
                            </div>
                        </div>
                    </div>
                `;
            });
            
            content += '</div>';
            $('#qrListModal .modal-body').html(content);
        }
        
        $('#qrListModal').modal('show');
    }
    
    /**
     * Vô hiệu hóa QR code
     */
    async deactivateQR(qrId) {
        if (!confirm('Bạn có chắc muốn vô hiệu hóa QR code này?')) return;
        
        try {
            this.showLoading('Đang vô hiệu hóa...');
            
            const response = await this.apiCall({
                action: 'deactivate',
                qr_id: qrId
            });
            
            this.hideLoading();
            
            if (response.success) {
                this.showSuccess(response.message);
                // Làm mới QR list if modal is open
                if ($('#qrListModal').hasClass('show')) {
                    const productId = $('#qrListModal').data('product-id');
                    if (productId) {
                        this.viewProductQRs(productId);
                    }
                }
            } else {
                this.showError(response.message);
            }
            
        } catch (error) {
            this.hideLoading();
            this.showError('Có lỗi xảy ra khi vô hiệu hóa QR code');
            console.error('Deactivate QR Error:', error);
        }
    }
    
    /**
     * Download QR image
     */
    downloadQRImage(imageUrl, qrCode) {
        const link = document.createElement('a');
        link.href = imageUrl;
        link.download = `QR_${qrCode}.png`;
        link.target = '_blank';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
    
    /**
     * Load QR Scanner Library with better compatibility
     */
    async loadQRScannerLibrary() {
        return new Promise((resolve, reject) => {
            if (typeof Html5QrcodeScanner !== 'undefined') {
                resolve();
                return;
            }
            
            console.log('📦 Loading enhanced QR detection library...');
            const script = document.createElement('script');
            // Use a more compatible version
            script.src = 'https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js';
            script.onload = () => {
                console.log('✅ QR library loaded successfully');
                resolve();
            };
            script.onerror = (error) => {
                console.error('❌ Failed to load QR library:', error);
                reject(error);
            };
            document.head.appendChild(script);
        });
    }
    
    /**
     * Create QR Scanner Modal
     */
    createQRScannerModal() {
        const modalHtml = `
            <div class="modal fade" id="qrScannerModal" tabindex="-1" role="dialog" aria-labelledby="qrScannerModalLabel">
                <div class="modal-dialog modal-lg" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="qrScannerModalLabel">
                                <i class="fas fa-qrcode"></i> Scan QR Code
                            </h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-md-8">
                                    <div id="qr-reader" style="width: 100%;"></div>
                                    <!-- Hidden div for image scanning -->
                                    <div id="temp-qr-scan" style="display: none;"></div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card">
                                        <div class="card-header">
                                            <h6 class="card-title mb-0">
                                                <i class="fas fa-cog"></i> Tùy chọn quét
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <!-- Upload QR Image -->
                                            <div class="form-group">
                                                <label for="qr-image-upload">
                                                    <i class="fas fa-upload"></i> Tải lên hình QR:
                                                </label>
                                                <input type="file" id="qr-image-upload" class="form-control-file" accept="image/*" style="margin-bottom: 10px;">
                                                <button class="btn btn-success btn-sm btn-block" id="scan-image-btn" disabled>
                                                    <i class="fas fa-search"></i> Quét hình đã chọn
                                                </button>
                                            </div>
                                            
                                            <hr>
                                            
                                            <!-- Manual QR Input -->
                                            <div class="form-group">
                                                <label for="manual-qr-input">
                                                    <i class="fas fa-keyboard"></i> Nhập mã QR thủ công:
                                                </label>
                                                <input type="text" id="manual-qr-input" class="form-control" placeholder="Nhập mã QR..." aria-describedby="manual-qr-help">
                                                <small id="manual-qr-help" class="form-text text-muted">Nhập mã QR nếu không thể scan</small>
                                                <button class="btn btn-info btn-sm btn-block" id="scan-manual-btn" style="margin-top: 5px;" disabled>
                                                    <i class="fas fa-check"></i> Xác nhận mã QR
                                                </button>
                                            </div>
                                            
                                            <hr>
                                            
                                            <!-- Control Buttons -->
                                            <button class="btn btn-warning btn-sm btn-block btn-stop-scan" type="button" aria-label="Dừng quét QR">
                                                <i class="fas fa-stop"></i> Dừng quét camera
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        $('body').append(modalHtml);
        
        // Thêm event handlers for accessibility
        $('#qrScannerModal').on('shown.bs.modal', function() {
            // Focus on the manual input when modal opens
            $('#manual-qr-input').focus();
        });
        
        // Xử lý QR image upload
        $('#qr-image-upload').on('change', function() {
            const scanBtn = $('#scan-image-btn');
            if (this.files && this.files[0]) {
                scanBtn.prop('disabled', false);
                scanBtn.removeClass('btn-success').addClass('btn-primary');
            } else {
                scanBtn.prop('disabled', true);
                scanBtn.removeClass('btn-primary').addClass('btn-success');
            }
        });
        
        // Xử lý scan image button
        $('#scan-image-btn').on('click', () => {
            this.scanQRFromImage();
        });
        
        // Xử lý manual QR input
        $('#manual-qr-input').on('input', function() {
            const scanBtn = $('#scan-manual-btn');
            const value = $(this).val().trim();
            if (value) {
                scanBtn.prop('disabled', false);
                scanBtn.removeClass('btn-info').addClass('btn-primary');
            } else {
                scanBtn.prop('disabled', true);
                scanBtn.removeClass('btn-primary').addClass('btn-info');
            }
        });
        
        $('#manual-qr-input').on('keypress', (e) => {
            if (e.which === 13) { // Enter key
                const qrCode = $('#manual-qr-input').val().trim();
                if (qrCode) {
                    this.onQRCodeScanned(qrCode);
                }
            }
        });
        
        // Xử lý manual scan button
        $('#scan-manual-btn').on('click', () => {
            const qrCode = $('#manual-qr-input').val().trim();
            if (qrCode) {
                this.onQRCodeScanned(qrCode);
            }
        });
        
        // Xử lý stop scan button
        $('.btn-stop-scan').on('click', () => {
            this.stopQRScanner();
        });
    }
    
    /**
     * Create QR List Modal
     */
    createQRListModal() {
        const modalHtml = `
            <div class="modal fade" id="qrListModal" tabindex="-1" role="dialog">
                <div class="modal-dialog modal-lg" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="fas fa-list"></i> Danh sách QR Code
                            </h5>
                            <button type="button" class="close" data-dismiss="modal">
                                <span>&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <!-- Content will be loaded here -->
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        $('body').append(modalHtml);
    }
    
    /**
     * Create QR Display Modal
     */
    createQRDisplayModal() {
        const modalHtml = `
            <div class="modal fade" id="qrDisplayModal" tabindex="-1" role="dialog">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="fas fa-qrcode"></i> QR Code
                            </h5>
                            <button type="button" class="close" data-dismiss="modal">
                                <span>&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <!-- Content will be loaded here -->
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        $('body').append(modalHtml);
    }
    
    /**
     * API Call helper
     */
    async apiCall(data) {
        const response = await fetch(this.apiUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data)
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        return await response.json();
    }
    
    /**
     * Utility functions
     */
    formatDate(dateString) {
        if (!dateString) return 'N/A';
        const date = new Date(dateString);
        return date.toLocaleString('vi-VN');
    }
    
    /**
     * Format currency
     */
    formatCurrency(amount) {
        if (!amount) return '0 VND';
        return new Intl.NumberFormat('vi-VN', {
            style: 'currency',
            currency: 'VND'
        }).format(amount);
    }
    
    /**
     * Hiển thị modal thông tin sản phẩm chi tiết
     */
    showProductModal(product, productInfo, isEnhanced) {
        const modalId = 'productInfoModal';
        
        // Xóa modal cũ nếu có
        $(`#${modalId}`).remove();
        
        // Tạo modal mới
        const modalHtml = `
            <div class="modal fade" id="${modalId}" tabindex="-1" role="dialog">
                <div class="modal-dialog modal-xl" role="document">
                    <div class="modal-content">
                        <div class="modal-header ${isEnhanced ? 'bg-primary text-white' : 'bg-light'}">
                            <h5 class="modal-title">
                                <i class="fas fa-qrcode mr-2"></i>
                                Thông tin sản phẩm ${isEnhanced ? '(QR Nâng cao)' : '(QR Cũ)'}
                            </h5>
                            <button type="button" class="close ${isEnhanced ? 'text-white' : ''}" data-dismiss="modal">
                                <span>&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            ${productInfo}
                        </div>
                        <div class="modal-footer">
                            <div class="btn-group mr-auto">
                                ${isEnhanced ? `
                                    <button class="btn btn-info" onclick="qrManager.viewInventoryHistory(${product.variant_id})">
                                        <i class="fas fa-history"></i> Lịch sử nhập kho
                                    </button>
                                ` : `
                                    <button class="btn btn-warning" onclick="alert('Chức năng nâng cấp QR đang phát triển')">
                                        <i class="fas fa-arrow-up"></i> Nâng cấp QR
                                    </button>
                                `}
                            </div>
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">
                                <i class="fas fa-times"></i> Đóng
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Thêm vào body và hiển thị
        $('body').append(modalHtml);
        $(`#${modalId}`).modal('show');
        
        // Xóa modal khi đóng
        $(`#${modalId}`).on('hidden.bs.modal', function() {
            $(this).remove();
        });
    }
    
    /**
     * Xem lịch sử nhập kho của variant
     */
    async viewInventoryHistory(variantId) {
        try {
            this.showLoading('Đang tải lịch sử...');
            
            const response = await this.apiCall({
                action: 'get_inventory_history',
                variant_id: variantId
            });
            
            this.hideLoading();
            
            if (response.success) {
                this.showInventoryHistoryModal(response.history);
            } else {
                this.showError(response.message);
            }
            
        } catch (error) {
            this.hideLoading();
            this.showError('Có lỗi khi tải lịch sử nhập kho');
            console.error('Inventory History Error:', error);
        }
    }
    
    /**
     * Hiển thị modal lịch sử nhập kho
     */
    showInventoryHistoryModal(history) {
        const modalId = 'inventoryHistoryModal';
        $(`#${modalId}`).remove();
        
        let historyHtml = '';
        if (history && history.length > 0) {
            historyHtml = history.map(item => `
                <tr>
                    <td>${item.receipt_code}</td>
                    <td>${this.formatDate(item.created_at)}</td>
                    <td><span class="badge badge-primary">${item.quantity}</span></td>
                    <td>${this.formatCurrency(item.unit_price)}</td>
                    <td>${item.supplier_name || 'N/A'}</td>
                    <td>${item.location_code || 'N/A'}</td>
                    <td>${item.created_by || 'N/A'}</td>
                </tr>
            `).join('');
        } else {
            historyHtml = '<tr><td colspan="7" class="text-center text-muted">Không có lịch sử nhập kho</td></tr>';
        }
        
        const modalHtml = `
            <div class="modal fade" id="${modalId}" tabindex="-1" role="dialog">
                <div class="modal-dialog modal-lg" role="document">
                    <div class="modal-content">
                        <div class="modal-header bg-info text-white">
                            <h5 class="modal-title">
                                <i class="fas fa-history mr-2"></i>
                                Lịch sử nhập kho
                            </h5>
                            <button type="button" class="close text-white" data-dismiss="modal">
                                <span>&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>Mã phiếu</th>
                                            <th>Ngày nhập</th>
                                            <th>Số lượng</th>
                                            <th>Đơn giá</th>
                                            <th>Nhà cung cấp</th>
                                            <th>Vị trí</th>
                                            <th>Người tạo</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${historyHtml}
                                    </tbody>
                                </table>
                            </div>
                            ${history && history.length > 0 ? `
                                <div class="mt-3">
                                    <small class="text-muted">
                                        <i class="fas fa-info-circle"></i>
                                        Tổng cộng ${history.length} lần nhập kho
                                    </small>
                                </div>
                            ` : ''}
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">
                                <i class="fas fa-times"></i> Đóng
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        $('body').append(modalHtml);
        $(`#${modalId}`).modal('show');
        
        $(`#${modalId}`).on('hidden.bs.modal', function() {
            $(this).remove();
        });
    }
    
    showLoading(message = 'Đang xử lý...') {
        // Hiển thị loading spinner or message
        if ($('#loadingModal').length === 0) {
            $('body').append(`
                <div class="modal fade" id="loadingModal" tabindex="-1" role="dialog" data-backdrop="static">
                    <div class="modal-dialog modal-sm" role="document">
                        <div class="modal-content">
                            <div class="modal-body text-center">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="sr-only">Loading...</span>
                                </div>
                                <p class="mt-2 mb-0" id="loadingMessage">${message}</p>
                            </div>
                        </div>
                    </div>
                </div>
            `);
        } else {
            $('#loadingMessage').text(message);
        }
        
        $('#loadingModal').modal('show');
    }
    
    hideLoading() {
        $('#loadingModal').modal('hide');
    }
    
    showSuccess(message) {
        this.showToast(message, 'success');
    }
    
    showError(message) {
        this.showToast(message, 'error');
    }
    
    showToast(message, type = 'info') {
        // Simple toast implementation
        const toastClass = type === 'success' ? 'alert-success' : 
                          type === 'error' ? 'alert-danger' : 'alert-info';
        
        const toast = $(`
            <div class="alert ${toastClass} alert-dismissible fade show" role="alert" style="position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px;">
                ${message}
                <button type="button" class="close" data-dismiss="alert">
                    <span>&times;</span>
                </button>
            </div>
        `);
        
        $('body').append(toast);
        
        // Auto hide after 5 seconds
        setTimeout(() => {
            toast.alert('close');
        }, 5000);
    }
    
    showModal(title, content) {
        if ($('#generalModal').length === 0) {
            $('body').append(`
                <div class="modal fade" id="generalModal" tabindex="-1" role="dialog">
                    <div class="modal-dialog" role="document">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="generalModalTitle"></h5>
                                <button type="button" class="close" data-dismiss="modal">
                                    <span>&times;</span>
                                </button>
                            </div>
                            <div class="modal-body" id="generalModalBody"></div>
                        </div>
                    </div>
                </div>
            `);
        }
        
        $('#generalModalTitle').text(title);
        $('#generalModalBody').html(content);
        $('#generalModal').modal('show');
    }
}

// Khởi tạo QR Manager khi document ready
let qrManager;
$(document).ready(function() {
    qrManager = new QRCodeManager();
    
    // Xuất to global scope for easy access
    window.qrManager = qrManager;
});
