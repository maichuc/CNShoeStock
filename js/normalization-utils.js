/**
 * ============================================
 * NORMALIZATION UTILITIES
 * ============================================
 * Module tập trung các hàm chuẩn hóa dữ liệu
 * Sử dụng trong: them_san_pham_ai.php, tao_phieu_nhap_moi.php
 * 
 * @version 1.0.0
 * @date 2024-12-08
 */

(function(window) {
    'use strict';

    /**
     * Chuẩn hóa loại sản phẩm (Product Type)
     * @param {string} type - Loại sản phẩm cần chuẩn hóa
     * @returns {string} - Loại sản phẩm đã chuẩn hóa
     */
    function standardizeProductType(type) {
        if (!type) return '';
        
        const typeMapping = {
            // Giày thể thao - Giữ nguyên "Sneaker"
            'sneaker': 'Sneaker',
            'sneakers': 'Sneaker',
            'giày thể thao': 'Sneaker',
            'giay the thao': 'Sneaker',
            'running': 'Sneaker',
            'running shoe': 'Sneaker',
            'running shoes': 'Sneaker',
            'training': 'Sneaker',
            'training shoe': 'Sneaker',
            'training shoes': 'Sneaker',
            'casual': 'Sneaker',
            'casual shoe': 'Sneaker',
            'casual shoes': 'Sneaker',
            'lifestyle': 'Sneaker',
            'basketball': 'Sneaker',
            'basketball shoe': 'Sneaker',
            'basketball shoes': 'Sneaker',
            'tennis': 'Sneaker',
            'tennis shoe': 'Sneaker',
            'tennis shoes': 'Sneaker',
            'walking shoe': 'Sneaker',
            'walking shoes': 'Sneaker',
            'gym shoe': 'Sneaker',
            'gym shoes': 'Sneaker',
            'athletic shoe': 'Sneaker',
            'athletic shoes': 'Sneaker',
            'sport shoe': 'Sneaker',
            'sport shoes': 'Sneaker',
            'sports shoe': 'Sneaker',
            'sports shoes': 'Sneaker',
            'giày chạy bộ': 'Sneaker',
            'giay chay bo': 'Sneaker',
            'giày tập gym': 'Sneaker',
            'giay tap gym': 'Sneaker',
            'giày tập luyện': 'Sneaker',
            'giay tap luyen': 'Sneaker',
            'giày vải': 'Sneaker',
            'giay vai': 'Sneaker',
            'giày canvas': 'Sneaker',
            'giay canvas': 'Sneaker',
            'converse': 'Sneaker',
            'vans': 'Sneaker',
            'giày thời trang': 'Sneaker',
            'giay thoi trang': 'Sneaker',
            'giày casual nữ': 'Sneaker',
            'giay casual nu': 'Sneaker',
            'giày casual nam': 'Sneaker',
            'giay casual nam': 'Sneaker',
            
            // Sandal - Giữ nguyên "Sandal"
            'sandal': 'Sandal',
            'sandals': 'Sandal',
            'dép': 'Sandal',
            'dep': 'Sandal',
            'slide': 'Sandal',
            'flip-flop': 'Sandal',
            'flipflop': 'Sandal',
            'sandal quai': 'Sandal',
            'sandal nữ': 'Sandal',
            'sandal nu': 'Sandal',
            'sandal nam': 'Sandal',
            'sandal trẻ em': 'Sandal',
            'sandal tre em': 'Sandal',
            'sandal bít mũi': 'Sandal',
            'sandal bit mui': 'Sandal',
            'sandal hở mũi': 'Sandal',
            'sandal ho mui': 'Sandal',
            
            // Giày cao gót
            'high heels': 'Giày cao gót',
            'high heel': 'Giày cao gót',
            'cao gót': 'Giày cao gót',
            'cao got': 'Giày cao gót',
            'giày cao gót': 'Giày cao gót',
            'giay cao got': 'Giày cao gót',
            'pump': 'Giày cao gót',
            'pumps': 'Giày cao gót',
            'stiletto': 'Giày cao gót',
            'stilettos': 'Giày cao gót',
            'block heel': 'Giày cao gót',
            'kitten heels': 'Giày cao gót',
            'kitten heel': 'Giày cao gót',
            'slingback': 'Giày cao gót',
            'slingbacks': 'Giày cao gót',
            'giày gót nhọn': 'Giày cao gót',
            'giay got nhon': 'Giày cao gót',
            'giày gót vuông': 'Giày cao gót',
            'giay got vuong': 'Giày cao gót',
            'giày gót thấp': 'Giày cao gót',
            'giay got thap': 'Giày cao gót',
            'giày cao cổ': 'Giày cao gót',
            'giay cao co': 'Giày cao gót',
            'platform': 'Giày cao gót',
            'platform shoe': 'Giày cao gót',
            'platform shoes': 'Giày cao gót',
            'giày đế cao': 'Giày cao gót',
            'giay de cao': 'Giày cao gót',
            
            // Giày đế xuồng (QUAN TRỌNG - phải match trước sandal)
            'wedge': 'Giày đế xuồng',
            'wedges': 'Giày đế xuồng',
            'đế xuồng': 'Giày đế xuồng',
            'de xuong': 'Giày đế xuồng',
            'giày đế xuồng': 'Giày đế xuồng',
            'sandal đế xuồng': 'Giày đế xuồng',
            'sandal de xuong': 'Giày đế xuồng',
            'sandal bịt mũi đế xuồng': 'Giày đế xuồng',
            'sandal bit mui de xuong': 'Giày đế xuồng',
            'wedge sandal': 'Giày đế xuồng',
            'wedge sandals': 'Giày đế xuồng',
            'giày sandal đế xuồng': 'Giày đế xuồng',
            'giay sandal de xuong': 'Giày đế xuồng',
            'sandal nữ đế xuồng': 'Giày đế xuồng',
            'sandal nu de xuong': 'Giày đế xuồng',
            'giày đế bằng xuồng': 'Giày đế xuồng',
            'giay de bang xuong': 'Giày đế xuồng',
            'giày nữ đế xuồng': 'Giày đế xuồng',
            'giay nu de xuong': 'Giày đế xuồng',
            'giày cao gót đế xuồng': 'Giày đế xuồng',
            'giay cao got de xuong': 'Giày đế xuồng',
            'wedge heel': 'Giày đế xuồng',
            'wedge heel sandal': 'Giày đế xuồng',
            'wedge heel shoe': 'Giày đế xuồng',
            'platform wedge': 'Giày đế xuồng',
            
            // Giày bệt
            'flat': 'Giày bệt',
            'flats': 'Giày bệt',
            'giày bệt': 'Giày bệt',
            'giay bet': 'Giày bệt',
            'ballet flat': 'Giày bệt',
            'ballet flats': 'Giày bệt',
            'giày búp bê': 'Giày bệt',
            'bệt': 'Giày bệt',
            'bet': 'Giày bệt',
            'giày đế bằng': 'Giày bệt',
            'giay de bang': 'Giày bệt',
            'giày búp bê nữ': 'Giày bệt',
            'giay bup be nu': 'Giày bệt',
            'giày ballerina': 'Giày bệt',
            'giay ballerina': 'Giày bệt',
            'giày êm chân': 'Giày bệt',
            'giay em chan': 'Giày bệt',
            'mary jane': 'Giày bệt',
            'mary janes': 'Giày bệt',
            'giày mary jane': 'Giày bệt',
            'espadrille': 'Giày bệt',
            'espadrilles': 'Giày bệt',
            'giày cói': 'Giày bệt',
            'giay coi': 'Giày bệt',
            
            // Giày lười
            'loafer': 'Giày lười',
            'loafers': 'Giày lười',
            'giày lười': 'Giày lười',
            'giay luoi': 'Giày lười',
            'slip-on': 'Giày lười',
            'slip on': 'Giày lười',
            'slipon': 'Giày lười',
            'moccasin': 'Giày lười',
            'moccasins': 'Giày lười',
            'boat shoe': 'Giày lười',
            'boat shoes': 'Giày lười',
            'giày không dây': 'Giày lười',
            'giay khong day': 'Giày lười',
            'giày slip on': 'Giày lười',
            'giay slip on': 'Giày lười',
            'giày lười nam': 'Giày lười',
            'giay luoi nam': 'Giày lười',
            'giày lười nữ': 'Giày lười',
            'giay luoi nu': 'Giày lười',
            'giày da lười': 'Giày lười',
            'giay da luoi': 'Giày lười',
            'penny loafer': 'Giày lười',
            'penny loafers': 'Giày lười',
            'driving shoe': 'Giày lười',
            'driving shoes': 'Giày lười',
            'giày lái xe': 'Giày lười',
            'giay lai xe': 'Giày lười',
            
            // Giày tây
            'oxford': 'Giày tây',
            'oxfords': 'Giày tây',
            'giày tây': 'Giày tây',
            'giay tay': 'Giày tây',
            'dress shoe': 'Giày tây',
            'dress shoes': 'Giày tây',
            'formal shoe': 'Giày tây',
            'formal shoes': 'Giày tây',
            'business shoe': 'Giày tây',
            'derby': 'Giày tây',
            'brogue': 'Giày tây',
            'giày da nam': 'Giày tây',
            'giay da nam': 'Giày tây',
            'giày công sở': 'Giày tây',
            'giay cong so': 'Giày tây',
            'giày công sở nam': 'Giày tây',
            'giay cong so nam': 'Giày tây',
            'giày dây': 'Giày tây',
            'giay day': 'Giày tây',
            'giày buộc dây': 'Giày tây',
            'giay buoc day': 'Giày tây',
            'giày oxford': 'Giày tây',
            'giay oxford': 'Giày tây',
            'giày oxford nam': 'Giày tây',
            'giay oxford nam': 'Giày tây',
            'monk strap': 'Giày tây',
            'monk straps': 'Giày tây',
            'giày monk': 'Giày tây',
            
            // Boot
            'boot': 'Boot',
            'boots': 'Boot',
            'giày boot': 'Boot',
            'giay boot': 'Boot',
            'ankle boot': 'Boot',
            'ankle boots': 'Boot',
            'chelsea boot': 'Boot',
            'chelsea boots': 'Boot',
            'combat boot': 'Boot',
            'combat boots': 'Boot',
            'knee boot': 'Boot',
            'knee boots': 'Boot',
            'bốt': 'Boot',
            'bot': 'Boot',
            'giày bốt': 'Boot',
            'giay bot': 'Boot',
            'boot cao cổ': 'Boot',
            'boot cao co': 'Boot',
            'boot cổ ngắn': 'Boot',
            'boot co ngan': 'Boot',
            'boot da': 'Boot',
            'boot cao gót': 'Boot',
            'boot cao got': 'Boot',
            'martin': 'Boot',
            'dr martens': 'Boot',
            'dr. martens': 'Boot',
            
            // Giày mules
            'mule': 'Giày mules',
            'mules': 'Giày mules',
            'giày mules': 'Giày mules',
            'giày không gót': 'Giày mules',
            'giay khong got': 'Giày mules',
            'giày hở gót': 'Giày mules',
            'giay ho got': 'Giày mules',
            'giày sục': 'Giày mules',
            'giay suc': 'Giày mules',
            
            // Giày quai hậu
            'quai hậu': 'Giày quai hậu',
            'quai hau': 'Giày quai hậu',
            'giày quai hậu': 'Giày quai hậu',
            'giày có quai hậu': 'Giày quai hậu',
            'giay co quai hau': 'Giày quai hậu',
            'giày quai sau': 'Giày quai hậu',
            'giay quai sau': 'Giày quai hậu',
            'slingback heels': 'Giày quai hậu',
            'slingback pumps': 'Giày quai hậu',
            
            // Dép (các biến thể)
            'dép lê': 'Dép',
            'dep le': 'Dép',
            'dép đi trong nhà': 'Dép',
            'dep di trong nha': 'Dép',
            'dép xỏ ngón': 'Dép',
            'dep xo ngon': 'Dép',
            'dép quai ngang': 'Dép',
            'dep quai ngang': 'Dép',
            'slipper': 'Dép',
            'slippers': 'Dép',
            'house slipper': 'Dép',
            'indoor slipper': 'Dép',
            
            // Giày thể thao chuyên dụng
            'football boot': 'Giày thể thao chuyên dụng',
            'football boots': 'Giày thể thao chuyên dụng',
            'soccer cleat': 'Giày thể thao chuyên dụng',
            'golf shoe': 'Giày thể thao chuyên dụng',
            'golf shoes': 'Giày thể thao chuyên dụng',
            'giày đá bóng': 'Giày thể thao chuyên dụng',
            'giay da bong': 'Giày thể thao chuyên dụng',
        };
        
        const typeLower = type.trim().toLowerCase();
        
        // Sắp xếp keys theo độ dài giảm dần để kiểm tra cụm từ dài trước
        const sortedKeys = Object.keys(typeMapping).sort((a, b) => b.length - a.length);
        
        // Kiểm tra exact match trước
        if (typeMapping[typeLower]) {
            return typeMapping[typeLower];
        }
        
        // Nếu không có exact match, tìm partial match với cụm từ dài nhất
        for (const key of sortedKeys) {
            if (typeLower.includes(key)) {
                console.log(`🔍 Partial match: '${typeLower}' contains '${key}' → '${typeMapping[key]}'`);
                return typeMapping[key];
            }
        }
        
        // Nếu không tìm thấy, giữ nguyên
        return type;
    }

    /**
     * Chuẩn hóa màu sắc (Color)
     * @param {string} color - Màu sắc cần chuẩn hóa
     * @returns {string} - Màu sắc đã chuẩn hóa
     */
    function standardizeColor(color) {
        if (!color) return '';
        
        // Kiểm tra nếu đã là tiếng Việt thì không translate lại
        const vietnameseColors = [
            'đen', 'trắng', 'đỏ', 'xanh', 'vàng', 'cam', 'tím', 'hồng', 'nâu', 'xám',
            'xanh dương', 'xanh lá', 'xanh navy', 'xanh lục', 'xanh bạc hà',
            'trắng kem', 'vàng kim', 'đỏ burgundy', 'bạc', 'đồng', 'beige', 'nude',
            'đậm', 'nhạt', 'tươi', 'pastel', 'kem'
        ];
        
        const colorLowerCheck = color.trim().toLowerCase();
        const hasVietnamese = vietnameseColors.some(vnColor => colorLowerCheck.includes(vnColor));
        
        if (hasVietnamese) {
            console.log(`✅ Color already in Vietnamese: "${color}" - Keeping as is`);
            return color.charAt(0).toUpperCase() + color.slice(1);
        }
        
        const colorMapping = {
            // Basic colors
            'black': 'Đen', 'white': 'Trắng', 'red': 'Đỏ',
            'blue': 'Xanh dương', 'green': 'Xanh lá', 'yellow': 'Vàng',
            'orange': 'Cam', 'purple': 'Tím', 'pink': 'Hồng',
            'brown': 'Nâu', 'gray': 'Xám', 'grey': 'Xám',
            
            // Special colors
            'beige': 'Beige', 'cream': 'Trắng kem', 'nude': 'Nude',
            'gold': 'Vàng kim', 'silver': 'Bạc', 'bronze': 'Đồng',
            'copper': 'Đồng đỏ', 'rose gold': 'Vàng hồng', 'rosegold': 'Vàng hồng',
            
            // Vietnamese variations
            'đen': 'Đen', 'den': 'Đen', 'trắng': 'Trắng', 'trang': 'Trắng',
            'đỏ': 'Đỏ', 'do': 'Đỏ', 'xanh': 'Xanh dương',
            'xanh dương': 'Xanh dương', 'xanh duong': 'Xanh dương',
            'xanh lá': 'Xanh lá', 'xanh la': 'Xanh lá', 'xanh lá cây': 'Xanh lá',
            'vàng': 'Vàng', 'vang': 'Vàng', 'cam': 'Cam',
            'tím': 'Tím', 'tim': 'Tím', 'hồng': 'Hồng', 'hong': 'Hồng',
            'nâu': 'Nâu', 'nau': 'Nâu', 'xám': 'Xám', 'xam': 'Xám',
            
            // Blue family
            'navy': 'Xanh navy', 'navy blue': 'Xanh navy',
            'royal blue': 'Xanh hoàng gia', 'sky blue': 'Xanh da trời',
            'baby blue': 'Xanh baby', 'powder blue': 'Xanh phấn',
            'turquoise': 'Xanh ngọc', 'teal': 'Xanh lục',
            'cyan': 'Xanh cyan', 'aqua': 'Xanh nước biển',
            
            // Green family
            'lime': 'Xanh chanh', 'lime green': 'Xanh chanh',
            'olive': 'Xanh ô liu', 'olive green': 'Xanh ô liu',
            'mint': 'Xanh bạc hà', 'mint green': 'Xanh bạc hà',
            'emerald': 'Xanh lục bảo', 'forest green': 'Xanh rừng',
            
            // Red family
            'burgundy': 'Đỏ burgundy', 'wine': 'Đỏ rượu',
            'wine red': 'Đỏ rượu', 'maroon': 'Đỏ nâu',
            'crimson': 'Đỏ thẫm', 'scarlet': 'Đỏ tươi',
            'cherry': 'Đỏ cherry', 'cherry red': 'Đỏ cherry',
            
            // Pink family
            'hot pink': 'Hồng cánh sen', 'fuchsia': 'Hồng fuchsia',
            'magenta': 'Hồng magenta', 'rose': 'Hồng rose',
            'blush': 'Hồng phấn', 'blush pink': 'Hồng phấn',
            'baby pink': 'Hồng baby', 'salmon': 'Hồng cam',
            'coral': 'San hô', 'peach': 'Hồng đào',
            
            // Purple family
            'lavender': 'Tím lavender', 'lilac': 'Tím lilac',
            'violet': 'Tím violet', 'plum': 'Tím mận',
            
            // Brown family
            'tan': 'Nâu nhạt', 'taupe': 'Nâu xám',
            'khaki': 'Kaki', 'camel': 'Nâu lạc đà',
            'chocolate': 'Nâu sô cô la', 'coffee': 'Nâu cà phê',
            
            // Neutrals
            'ivory': 'Ngà', 'champagne': 'Champagne',
            'charcoal': 'Xám đen', 'slate': 'Xám đá phiến',
            
            // Combinations
            'light blue': 'Xanh dương nhạt', 'dark blue': 'Xanh dương đậm',
            'light pink': 'Hồng nhạt', 'dark pink': 'Hồng đậm',
            'light gray': 'Xám nhạt', 'dark gray': 'Xám đậm',
            'light grey': 'Xám nhạt', 'dark grey': 'Xám đậm',
            
            // Multicolor
            'multicolor': 'Nhiều màu', 'multi-color': 'Nhiều màu',
            'rainbow': 'Cầu vồng', 'colorful': 'Nhiều màu',
            
            // Transparent
            'transparent': 'Trong suốt', 'clear': 'Trong',
        };
        
        const colorLower = color.trim().toLowerCase();
        
        // Exact match
        if (colorMapping[colorLower]) {
            return colorMapping[colorLower];
        }
        
        // Multi-word translation
        const words = colorLower.split(/\s+/);
        if (words.length > 1) {
            const translatedWords = words.map(word => 
                colorMapping[word] || (word.charAt(0).toUpperCase() + word.slice(1))
            );
            return translatedWords.join(' ');
        }
        
        // Capitalize if not found
        return color.charAt(0).toUpperCase() + color.slice(1);
    }

    /**
     * Chuẩn hóa thương hiệu (Brand)
     * @param {string} brand - Thương hiệu cần chuẩn hóa
     * @returns {string} - Thương hiệu đã chuẩn hóa
     */
    function standardizeBrand(brand) {
        const unknownBrands = [
            'fashion', 'không xác định', 'khong xac dinh', 
            'chưa xác định', 'chua xac dinh',
            'không rõ', 'khong ro', 'chưa rõ', 'chua ro',
            'không thương hiệu', 'khong thuong hieu',
            'no brand', 'nobrand', 'no-brand',
            'unbranded', 'non-branded', 'undefined', 'none', 'null',
            'n/a', 'na', '-', '?', ''
        ];
        
        if (!brand || !brand.trim()) {
            return 'Unknown';
        }
        
        const brandLower = brand.trim().toLowerCase();
        
        if (unknownBrands.includes(brandLower)) {
            console.log(`⚠️ Standardizing brand "${brand}" to "Unknown"`);
            return 'Unknown';
        }
        
        return brand.trim().charAt(0).toUpperCase() + brand.trim().slice(1);
    }

    /**
     * Chuẩn hóa danh sách màu sắc
     * @param {Array|string} colors - Danh sách màu hoặc chuỗi màu
     * @returns {Array} - Mảng màu đã chuẩn hóa
     */
    function normalizeColors(colors) {
        if (!colors) return [];
        
        let colorArray = [];
        
        if (Array.isArray(colors)) {
            colorArray = colors.map(color => standardizeColor(color));
        } else if (typeof colors === 'string') {
            colorArray = colors.split(',')
                .map(color => standardizeColor(color.trim()))
                .filter(c => c);
        }
        
        // Xóa duplicate words trong mỗi màu
        const deduplicatedColors = colorArray.map(color => {
            const words = color.split(/\s+/);
            const deduped = [];
            let lastWord = '';
            
            for (const word of words) {
                if (word.toLowerCase() !== lastWord.toLowerCase()) {
                    deduped.push(word);
                    lastWord = word;
                }
            }
            
            return deduped.join(' ');
        }).filter(c => c);
        
        console.log('🎨 Normalized colors:', colorArray, '→', deduplicatedColors);
        
        return deduplicatedColors;
    }

    // Export functions to global scope
    window.NormalizationUtils = {
        standardizeProductType: standardizeProductType,
        standardizeColor: standardizeColor,
        standardizeBrand: standardizeBrand,
        normalizeColors: normalizeColors
    };

    // Backward compatibility - export to window directly
    window.standardizeProductType = standardizeProductType;
    window.standardizeColor = standardizeColor;
    window.standardizeBrand = standardizeBrand;
    window.normalizeColors = normalizeColors;

    console.log('✅ Normalization Utils loaded successfully');

})(window);
