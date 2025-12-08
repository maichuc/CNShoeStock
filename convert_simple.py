#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Simple comment converter - focused approach"""
import os
import re
from pathlib import Path

# Simple word-by-word mapping
WORDS = {
    'Initialize': 'Khởi tạo', 'Process': 'Xử lý', 'Parse': 'Phân tích', 'Validate': 'Kiểm tra',
    'Check': 'Kiểm tra', 'Handle': 'Xử lý', 'Get': 'Lấy', 'Set': 'Đặt', 'Load': 'Tải',
    'Save': 'Lưu', 'Update': 'Cập nhật', 'Create': 'Tạo', 'Generate': 'Tạo',
    'Delete': 'Xóa', 'Remove': 'Xóa', 'Add': 'Thêm', 'Insert': 'Thêm',
    'Calculate': 'Tính toán', 'Count': 'Đếm', 'Format': 'Định dạng', 'Build': 'Xây dựng',
    'Apply': 'Áp dụng', 'Merge': 'Gộp', 'Convert': 'Chuyển đổi', 'Extract': 'Trích xuất',
    'Filter': 'Lọc', 'Sort': 'Sắp xếp', 'Group': 'Nhóm', 'Map': 'Ánh xạ',
    'Execute': 'Thực thi', 'Run': 'Chạy', 'Start': 'Bắt đầu', 'Stop': 'Dừng',
    'Reset': 'Đặt lại', 'Clear': 'Xóa', 'Show': 'Hiển thị', 'Hide': 'Ẩn',
    'Display': 'Hiển thị', 'Render': 'Hiển thị', 'Toggle': 'Bật/tắt', 'Open': 'Mở',
    'Close': 'Đóng', 'Send': 'Gửi', 'Fetch': 'Lấy', 'Query': 'Truy vấn',
    'Select': 'Chọn', 'Search': 'Tìm kiếm', 'Find': 'Tìm', 'Return': 'Trả về',
}

def has_vietnamese(text):
    """Check if text has Vietnamese characters"""
    return bool(re.search(r'[àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđĐ]', text))

def translate_line(line):
    """Translate a single comment line"""
    # Extract comment text
    match = re.match(r'^(\s*)//\s*(.+)$', line)
    if not match:
        return None, None
    
    indent, text = match.groups()
    
    # Skip if already Vietnamese
    if has_vietnamese(text):
        return None, None
    
    # Translate word by word
    translated = text
    changed = False
    
    for eng, vie in WORDS.items():
        pattern = r'\b' + re.escape(eng) + r'\b'
        if re.search(pattern, translated):
            translated = re.sub(pattern, vie, translated)
            changed = True
    
    if not changed:
        return None, None
    
    return f"{indent}// {translated}\n", text

def process_file(filepath):
    """Process one file"""
    try:
        with open(filepath, 'r', encoding='utf-8') as f:
            lines = f.readlines()
    except:
        return 0
    
    new_lines = []
    count = 0
    
    for line in lines:
        translated, original = translate_line(line)
        if translated:
            new_lines.append(translated)
            count += 1
        else:
            new_lines.append(line)
    
    if count > 0:
        with open(filepath, 'w', encoding='utf-8') as f:
            f.writelines(new_lines)
    
    return count

def main():
    print("Converting comments...")
    
    # Get all PHP/JS/HTML files (excluding vendor)
    root = Path('.')
    files = []
    for pattern in ['*.php', '*.js', '*.html']:
        files.extend(root.rglob(pattern))
    
    total = 0
    modified = 0
    
    for file in files:
        # Skip backup folders and vendor
        if 'backup' in str(file) or 'vendor' in str(file) or 'node_modules' in str(file) or 'phpqrcode' in str(file):
            continue
        
        count = process_file(file)
        if count > 0:
            print(f"[OK] {file.relative_to(root)}: {count} comments")
            total += count
            modified += 1
    
    print(f"\nTotal: {total} comments converted in {modified} files")

if __name__ == '__main__':
    main()
