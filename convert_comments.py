#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Auto Convert English Comments to Vietnamese
Converts all English code comments to Vietnamese in PHP/JS/HTML files
"""

import os
import re
import shutil
from pathlib import Path
from datetime import datetime

# Translation mapping (English → Vietnamese)
TRANSLATIONS = {
    # Common verbs
    'Initialize': 'Khởi tạo',
    'Process': 'Xử lý',
    'Parse': 'Phân tích cú pháp',
    'Validate': 'Kiểm tra',
    'Check': 'Kiểm tra',
    'Handle': 'Xử lý',
    'Get': 'Lấy',
    'Set': 'Đặt',
    'Load': 'Tải',
    'Save': 'Lưu',
    'Update': 'Cập nhật',
    'Create': 'Tạo',
    'Generate': 'Tạo',
    'Delete': 'Xóa',
    'Remove': 'Xóa',
    'Add': 'Thêm',
    'Insert': 'Thêm',
    'Calculate': 'Tính toán',
    'Count': 'Đếm',
    'Format': 'Định dạng',
    'Build': 'Xây dựng',
    'Apply': 'Áp dụng',
    'Merge': 'Gộp',
    'Convert': 'Chuyển đổi',
    'Extract': 'Trích xuất',
    'Filter': 'Lọc',
    'Sort': 'Sắp xếp',
    'Group': 'Nhóm',
    'Map': 'Ánh xạ',
    'Execute': 'Thực thi',
    'Run': 'Chạy',
    'Start': 'Bắt đầu',
    'Stop': 'Dừng',
    'Reset': 'Đặt lại',
    'Clear': 'Xóa',
    'Show': 'Hiển thị',
    'Hide': 'Ẩn',
    'Display': 'Hiển thị',
    'Render': 'Hiển thị',
    'Toggle': 'Bật/tắt',
    'Open': 'Mở',
    'Close': 'Đóng',
    'Send': 'Gửi',
    'Fetch': 'Lấy',
    'Query': 'Truy vấn',
    'Select': 'Chọn',
    'Search': 'Tìm kiếm',
    'Find': 'Tìm',
    'Return': 'Trả về',
    'Redirect': 'Chuyển hướng',
    'Connect': 'Kết nối',
    'Disconnect': 'Ngắt kết nối',
    'Upload': 'Tải lên',
    'Download': 'Tải xuống',
    'Import': 'Nhập',
    'Export': 'Xuất',
    'Copy': 'Sao chép',
    'Move': 'Di chuyển',
    'Encode': 'Mã hóa',
    'Decode': 'Giải mã',
    'Compress': 'Nén',
    'Decompress': 'Giải nén',
    'Encrypt': 'Mã hóa',
    'Decrypt': 'Giải mã',
    'Log': 'Ghi nhật ký',
    'Debug': 'Gỡ lỗi',
    'Test': 'Kiểm tra',
    'Verify': 'Xác minh',
    'Confirm': 'Xác nhận',
    'Cancel': 'Hủy',
    'Approve': 'Phê duyệt',
    'Reject': 'Từ chối',
    'Accept': 'Chấp nhận',
    'Deny': 'Từ chối',
    'Enable': 'Bật',
    'Disable': 'Tắt',
    'Activate': 'Kích hoạt',
    'Deactivate': 'Vô hiệu hóa',
    'Register': 'Đăng ký',
    'Login': 'Đăng nhập',
    'Logout': 'Đăng xuất',
    'Authenticate': 'Xác thực',
    'Authorize': 'Ủy quyền',
    'Refresh': 'Làm mới',
    'Reload': 'Tải lại',
    'Sync': 'Đồng bộ',
    'Backup': 'Sao lưu',
    'Restore': 'Khôi phục',
    'Migrate': 'Di chuyển',
    'Optimize': 'Tối ưu',
    'Scan': 'Quét',
    'Monitor': 'Giám sát',
    'Track': 'Theo dõi',
    'Subscribe': 'Đăng ký',
    'Unsubscribe': 'Hủy đăng ký',
    'Notify': 'Thông báo',
    'Alert': 'Cảnh báo',
    'Warn': 'Cảnh báo',
    'Error': 'Lỗi',
    'Success': 'Thành công',
    'Fail': 'Thất bại',
    'Complete': 'Hoàn thành',
    'Pending': 'Đang chờ',
    'Skip': 'Bỏ qua',
    'Continue': 'Tiếp tục',
    'Retry': 'Thử lại',
    'Wait': 'Đợi',
    'Sleep': 'Ngủ',
    'Pause': 'Tạm dừng',
    'Resume': 'Tiếp tục',
    'Prepare': 'Chuẩn bị',
    'Setup': 'Thiết lập',
    'Config': 'Cấu hình',
    'Configure': 'Cấu hình',
}

# Phrases mapping
PHRASE_TRANSLATIONS = {
    'if not already started': 'nếu chưa được khởi động',
    'for better error message': 'để hiển thị thông báo lỗi tốt hơn',
    'with basic info': 'với thông tin cơ bản',
    'if customer_id exists': 'nếu có customer_id',
    'if no customer info': 'nếu không có thông tin khách hàng',
    'for display': 'để hiển thị',
    'before deletion': 'trước khi xóa',
    'if table exists': 'nếu bảng tồn tại',
    'might not exist': 'có thể không tồn tại',
    'with cancellation reason': 'với lý do hủy',
    'if canceled': 'nếu bị hủy',
    'if provided': 'nếu có',
    'if query provided': 'nếu có truy vấn',
    'commented out for production': '(commented out for production)',
    'only if not already started': 'chỉ nếu chưa được khởi động',
    'to server': 'lên server',
    'from database': 'từ database',
    'in database': 'trong database',
    'to database': 'vào database',
    'from file': 'từ file',
    'to file': 'vào file',
    'from JSON': 'từ JSON',
    'to JSON': 'sang JSON',
    'if exists': 'nếu tồn tại',
    'if found': 'nếu tìm thấy',
    'if available': 'nếu có sẵn',
    'for this order': 'cho đơn hàng này',
    'for this warehouse': 'cho kho này',
    'for this product': 'cho sản phẩm này',
    'for this supplier': 'cho nhà cung cấp này',
    'is valid': 'có hợp lệ không',
    'is being used': 'có đang được sử dụng không',
}

def create_backup(file_path):
    """Create backup of file before modification"""
    backup_dir = Path('backup_comments')
    backup_dir.mkdir(exist_ok=True)
    
    timestamp = datetime.now().strftime('%Y%m%d_%H%M%S')
    backup_name = f"{file_path.stem}_{timestamp}{file_path.suffix}"
    backup_path = backup_dir / backup_name
    
    shutil.copy2(file_path, backup_path)
    return backup_path

def translate_comment(comment_text):
    """Translate English comment to Vietnamese"""
    original = comment_text.strip()
    translated = original
    
    # First translate phrases
    for eng, vie in PHRASE_TRANSLATIONS.items():
        translated = re.sub(rf'\b{re.escape(eng)}\b', vie, translated, flags=re.IGNORECASE)
    
    # Then translate individual words
    for eng, vie in TRANSLATIONS.items():
        # Match word boundaries to avoid partial replacements
        pattern = rf'\b{re.escape(eng)}\b'
        translated = re.sub(pattern, vie, translated)
    
    # If no translation occurred, return original
    if translated == original:
        return None
    
    return translated

def should_skip_file(file_path):
    """Check if file should be skipped"""
    skip_dirs = ['vendor', 'node_modules', 'classes/phpqrcode', 'backup_comments']
    path_str = str(file_path)
    
    for skip_dir in skip_dirs:
        if skip_dir in path_str:
            return True
    return False

def process_file(file_path):
    """Process a single file and convert comments"""
    if should_skip_file(file_path):
        return None
    
    try:
        with open(file_path, 'r', encoding='utf-8') as f:
            lines = f.readlines()
    except Exception as e:
        print(f"[ERROR] reading {file_path}: {e}")
        return None
    
    modified = False
    new_lines = []
    changes = []
    
    for i, line in enumerate(lines, 1):
        # Match single-line comments: // Comment text
        comment_match = re.match(r'^(\s*)//\s*(.+)$', line)
        
        if comment_match:
            indent = comment_match.group(1)
            comment_text = comment_match.group(2).strip()
            
            # Skip if already in Vietnamese or has common Vietnamese words
            vietnamese_patterns = [
                r'[àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđĐ]',
                r'\b(Kiểm tra|Xử lý|Lưu ý|Bước|Cần làm|Lấy|Tải|Tạo|Gửi|Hiển thị|Cập nhật|Khởi tạo|Phân tích|Gỡ lỗi|Thêm|Xóa|Đặt|Sao chép|Tính toán|Đếm|Áp dụng|Ẩn|Trả về|Định dạng|Bật|Ghi|Truy vấn|Chọn|Nhóm|Ánh xạ|Thực thi|Chạy|Bắt đầu|Dừng|Đặt lại|Mở|Đóng|Lọc|Sắp xếp)\b'
            ]
            
            skip_comment = False
            for pattern in vietnamese_patterns:
                if re.search(pattern, comment_text):
                    skip_comment = True
                    break
            
            if skip_comment:
                new_lines.append(line)
                continue
            
            # Try to translate
            translated = translate_comment(comment_text)
            
            if translated and translated != comment_text:
                new_line = f"{indent}// {translated}\n"
                new_lines.append(new_line)
                modified = True
                changes.append({
                    'line': i,
                    'old': comment_text,
                    'new': translated
                })
            else:
                new_lines.append(line)
        else:
            new_lines.append(line)
    
    if modified:
        # Create backup
        backup_path = create_backup(file_path)
        
        # Write modified content
        with open(file_path, 'w', encoding='utf-8') as f:
            f.writelines(new_lines)
        
        return {
            'file': file_path,
            'backup': backup_path,
            'changes': changes,
            'count': len(changes)
        }
    
    return None

def main():
    """Main function"""
    print("=" * 70)
    print("AUTO CONVERT ENGLISH COMMENTS TO VIETNAMESE")
    print("=" * 70)
    print()
    
    # Get project root
    project_root = Path(__file__).parent
    
    # Find all PHP, JS, HTML files
    extensions = ['*.php', '*.js', '*.html']
    all_files = []
    
    for ext in extensions:
        all_files.extend(project_root.rglob(ext))
    
    print(f"Found {len(all_files)} files to scan")
    print()
    
    # Process files
    results = []
    skipped = 0
    
    for file_path in all_files:
        if should_skip_file(file_path):
            skipped += 1
            continue
        
        result = process_file(file_path)
        if result:
            results.append(result)
            print(f"[OK] {result['file'].relative_to(project_root)}: {result['count']} comments converted")
    
    print()
    print("=" * 70)
    print("SUMMARY")
    print("=" * 70)
    print(f"Total files scanned: {len(all_files)}")
    print(f"Files skipped (vendor, etc.): {skipped}")
    print(f"Files modified: {len(results)}")
    
    total_changes = sum(r['count'] for r in results)
    print(f"Total comments converted: {total_changes}")
    
    if results:
        print()
        print("Backups created in: backup_comments/")
        print()
        print("Detailed changes:")
        for result in results[:10]:  # Show first 10
            print(f"\n  {result['file'].name}:")
            for change in result['changes'][:3]:  # Show first 3 changes per file
                print(f"    Line {change['line']}: {change['old'][:50]}...")
                print(f"              -> {change['new'][:50]}...")
    
    print()
    print("Done!")

if __name__ == '__main__':
    main()
