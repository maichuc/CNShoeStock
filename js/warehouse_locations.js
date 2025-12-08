/**
 * Warehouse Locations Management
 * Enhanced filtering, sorting, and pagination
 */

let allLocations = [];
let filteredLocations = [];
let currentPage = 1;

$(document).ready(function() {
    loadWarehouses();
    loadLocations();
    
    // Enter để tìm kiếm
    $('#searchInput').on('keypress', function(e) {
        if (e.which === 13) {
            loadLocations();
        }
    });
    
    // Auto search khi thay đổi filter
    $('#filterWarehouse, #filterStatus, #filterProductType, #sortBy, #sortOrder').on('change', function() {
        loadLocations();
    });
    
    // Load product types khi chọn warehouse
    $('#filterWarehouse').on('change', function() {
        const warehouseId = $(this).val();
        if (warehouseId) {
            loadFilterProductTypes(warehouseId);
        } else {
            $('#filterProductType').html('<option value="">📦 Tất cả loại</option>');
        }
    });
});

// Load danh sách locations
function loadLocations() {
    const warehouseId = $('#filterWarehouse').val();
    const search = $('#searchInput').val();
    const isActive = $('#filterStatus').val();
    const productType = $('#filterProductType').val();
    
    // Hiển thị loading
    $('#locationsTableBody').html(`
        <tr>
            <td colspan="7" class="text-center py-5">
                <i class="fas fa-spinner fa-spin fa-2x text-primary"></i>
                <p class="mt-2">Đang tải dữ liệu...</p>
            </td>
        </tr>
    `);
    
    $.ajax({
        url: 'api_warehouse_locations.php?action=list',
        type: 'GET',
        data: {
            warehouse_id: warehouseId,
            search: search,
            is_active: isActive,
            product_type: productType
        },
        success: function(response) {
            if (response.success) {
                allLocations = response.data;
                filteredLocations = [...allLocations];
                
                // Sắp xếp
                sortLocations();
                
                // Cập nhật statistics
                updateStatistics();
                
                // Display với phân trang
                currentPage = 1; // Reset to first page
                displayLocations();
            } else {
                showAlert('error', response.message || 'Không thể tải dữ liệu');
                $('#locationsTableBody').html(`
                    <tr><td colspan="7" class="text-center text-danger">❌ ${response.message || 'Không thể tải dữ liệu'}</td></tr>
                `);
            }
        },
        error: function(xhr) {
            console.error('Error:', xhr);
            showAlert('error', 'Lỗi kết nối đến server');
            $('#locationsTableBody').html(`
                <tr><td colspan="7" class="text-center text-danger">❌ Lỗi kết nối đến server</td></tr>
            `);
        }
    });
}

// Sắp xếp locations
function sortLocations() {
    const sortBy = $('#sortBy').val();
    const sortOrder = $('#sortOrder').val();
    
    filteredLocations.sort((a, b) => {
        let valA = a[sortBy];
        let valB = b[sortBy];
        
        // Chuyển đổi to string for comparison
        valA = valA ? valA.toString().toLowerCase() : '';
        valB = valB ? valB.toString().toLowerCase() : '';
        
        if (sortOrder === 'asc') {
            return valA > valB ? 1 : -1;
        } else {
            return valA < valB ? 1 : -1;
        }
    });
}

// Cập nhật statistics
function updateStatistics() {
    const total = filteredLocations.length;
    const active = filteredLocations.filter(loc => loc.is_active == 1).length;
    const inactive = total - active;
    
    $('#totalCount').text(`Tổng: ${total} vị trí`);
    $('#activeCount').text(`Hoạt động: ${active}`);
    $('#inactiveCount').text(`Không hoạt động: ${inactive}`);
    $('#resultCount').text(total);
}

// Hiển thị danh sách locations với phân trang
function displayLocations() {
    const tbody = $('#locationsTableBody');
    const entriesPerPage = $('#entriesPerPage').val();
    
    if (filteredLocations.length === 0) {
        tbody.html(`
            <tr>
                <td colspan="7" class="text-center py-4">
                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                    <p class="text-muted">Không tìm thấy vị trí kho nào</p>
                </td>
            </tr>
        `);
        $('#pagination').html('');
        $('#paginationInfo').html('');
        return;
    }
    
    // Phân trang
    let startIndex = 0;
    let endIndex = filteredLocations.length;
    
    if (entriesPerPage !== 'all') {
        const itemsPerPage = parseInt(entriesPerPage);
        const totalPages = Math.ceil(filteredLocations.length / itemsPerPage);
        
        // Ensure currentPage có hợp lệ không
        if (currentPage > totalPages) currentPage = totalPages;
        if (currentPage < 1) currentPage = 1;
        
        startIndex = (currentPage - 1) * itemsPerPage;
        endIndex = Math.min(startIndex + itemsPerPage, filteredLocations.length);
        
        // Hiển thị pagination
        renderPagination(totalPages, itemsPerPage);
    } else {
        $('#pagination').html('');
        $('#paginationInfo').html(`Hiển thị tất cả ${filteredLocations.length} vị trí`);
    }
    
    // Hiển thị data
    let html = '';
    const displayLocations = filteredLocations.slice(startIndex, endIndex);
    
    displayLocations.forEach(function(loc, index) {
        const statusBadge = loc.is_active == 1 
            ? '<span class="badge badge-success">Đang hoạt động</span>' 
            : '<span class="badge badge-secondary">Không hoạt động</span>';
        
        const createdAt = new Date(loc.created_at).toLocaleDateString('vi-VN');
        
        // Kiểm tra quyền chỉnh sửa/xóa cho manager
        const canEdit = (userRole === 'admin') || (userRole === 'manager' && userWarehouseId == loc.warehouse_id);
        const editButton = canEdit 
            ? `<button class="btn btn-sm btn-info btn-action" onclick="editLocation(${loc.location_id})" title="Sửa">
                <i class="fas fa-edit"></i>
               </button>`
            : `<button class="btn btn-sm btn-secondary btn-action" disabled title="Không có quyền">
                <i class="fas fa-edit"></i>
               </button>`;
        
        const deleteButton = canEdit
            ? `<button class="btn btn-sm btn-danger btn-action" onclick="deleteLocation(${loc.location_id}, '${loc.location_code}', ${loc.warehouse_id})" title="Xóa">
                <i class="fas fa-trash"></i>
               </button>`
            : `<button class="btn btn-sm btn-secondary btn-action" disabled title="Không có quyền">
                <i class="fas fa-trash"></i>
               </button>`;
        
        html += `
            <tr>
                <td class="text-center"><strong>${startIndex + index + 1}</strong></td>
                <td><span class="badge badge-primary">${loc.warehouse_name || 'N/A'}</span></td>
                <td><span class="location-code badge badge-light p-2">${loc.location_code}</span></td>
                <td><small>${loc.description || '<em class="text-muted">Chưa có mô tả</em>'}</small></td>
                <td class="text-center">${statusBadge}</td>
                <td><small><i class="far fa-clock"></i> ${createdAt}</small></td>
                <td class="text-center">
                    ${editButton}
                    ${deleteButton}
                </td>
            </tr>
        `;
    });
    
    tbody.html(html);
}

// Hiển thị pagination
function renderPagination(totalPages, itemsPerPage) {
    const startItem = (currentPage - 1) * itemsPerPage + 1;
    const endItem = Math.min(currentPage * itemsPerPage, filteredLocations.length);
    
    $('#paginationInfo').html(`Hiển thị ${startItem} - ${endItem} trong tổng số ${filteredLocations.length} vị trí`);
    
    if (totalPages <= 1) {
        $('#pagination').html('');
        return;
    }
    
    let paginationHtml = '';
    
    // Previous button
    paginationHtml += `
        <li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
            <a class="page-link" href="#" onclick="changePage(${currentPage - 1}); return false;">
                <i class="fas fa-chevron-left"></i>
            </a>
        </li>
    `;
    
    // Page numbers
    const maxPagesToShow = 5;
    let startPage = Math.max(1, currentPage - Math.floor(maxPagesToShow / 2));
    let endPage = Math.min(totalPages, startPage + maxPagesToShow - 1);
    
    if (endPage - startPage < maxPagesToShow - 1) {
        startPage = Math.max(1, endPage - maxPagesToShow + 1);
    }
    
    if (startPage > 1) {
        paginationHtml += `<li class="page-item"><a class="page-link" href="#" onclick="changePage(1); return false;">1</a></li>`;
        if (startPage > 2) {
            paginationHtml += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
        }
    }
    
    for (let i = startPage; i <= endPage; i++) {
        paginationHtml += `
            <li class="page-item ${i === currentPage ? 'active' : ''}">
                <a class="page-link" href="#" onclick="changePage(${i}); return false;">${i}</a>
            </li>
        `;
    }
    
    if (endPage < totalPages) {
        if (endPage < totalPages - 1) {
            paginationHtml += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
        }
        paginationHtml += `<li class="page-item"><a class="page-link" href="#" onclick="changePage(${totalPages}); return false;">${totalPages}</a></li>`;
    }
    
    // Next button
    paginationHtml += `
        <li class="page-item ${currentPage === totalPages ? 'disabled' : ''}">
            <a class="page-link" href="#" onclick="changePage(${currentPage + 1}); return false;">
                <i class="fas fa-chevron-right"></i>
            </a>
        </li>
    `;
    
    $('#pagination').html(paginationHtml);
}

// Change page
function changePage(page) {
    currentPage = page;
    displayLocations();
    // Scroll to top
    $('html, body').animate({ scrollTop: $('#locationsTable').offset().top - 100 }, 300);
}

// Đặt lại filters
function resetFilters() {
    $('#filterWarehouse').val('');
    $('#searchInput').val('');
    $('#filterStatus').val('');
    $('#filterProductType').val('').html('<option value="">📦 Tất cả loại</option>');
    $('#sortBy').val('location_code');
    $('#sortOrder').val('asc');
    $('#entriesPerPage').val('25');
    currentPage = 1;
    loadLocations();
}

// Xuất to Excel
function exportToExcel() {
    if (filteredLocations.length === 0) {
        showAlert('warning', 'Không có dữ liệu để xuất');
        return;
    }
    
    // Chuẩn bị data
    let csvContent = 'STT,Kho,Mã Vị trí,Mô tả,Trạng thái,Ngày tạo\n';
    
    filteredLocations.forEach((loc, index) => {
        const status = loc.is_active == 1 ? 'Đang hoạt động' : 'Không hoạt động';
        const description = (loc.description || '').replace(/,/g, ';').replace(/\n/g, ' ');
        csvContent += `${index + 1},${loc.warehouse_name},"${loc.location_code}","${description}",${status},${loc.created_at}\n`;
    });
    
    // Tạo download
    const blob = new Blob(["\uFEFF" + csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    const fileName = `vi_tri_kho_${new Date().getTime()}.csv`;
    
    link.setAttribute('href', url);
    link.setAttribute('download', fileName);
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    
    showAlert('success', `Đã xuất ${filteredLocations.length} vị trí ra file ${fileName}`);
}

// Tải product types for filter
function loadFilterProductTypes(warehouseId) {
    if (!warehouseId) {
        $('#filterProductType').html('<option value="">📦 Tất cả loại</option>');
        return;
    }
    
    $.ajax({
        url: `api_warehouse_locations.php?action=product_types&warehouse_id=${warehouseId}`,
        type: 'GET',
        success: function(response) {
            if (response.success && response.data) {
                let options = '<option value="">📦 Tất cả loại</option>';
                response.data.forEach(function(item) {
                    options += `<option value="${item.type}">${item.type}</option>`;
                });
                $('#filterProductType').html(options);
            } else {
                $('#filterProductType').html('<option value="">📦 Tất cả loại</option>');
            }
        },
        error: function() {
            $('#filterProductType').html('<option value="">📦 Tất cả loại</option>');
        }
    });
}
