<!-- Create Category Modal -->
<div class="modal fade" id="createCategoryModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-plus-circle text-success mr-2"></i>
                    Thêm Category Mới
                </h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_category">
                    
                    <div class="form-group">
                        <label for="categoryName">Tên Category <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="categoryName" name="category_name" 
                               placeholder="Nhập tên category..." required>
                    </div>
                    
                    <div class="form-group">
                        <label for="categoryDescription">Mô tả</label>
                        <textarea class="form-control" id="categoryDescription" name="category_description" 
                                  rows="3" placeholder="Nhập mô tả category..."></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle mr-2"></i>
                        Category này sẽ chỉ khả dụng cho warehouse <strong><?php echo htmlspecialchars($warehouse['name']); ?></strong>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save mr-1"></i>Tạo Category
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Category Modal -->
<div class="modal fade" id="editCategoryModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-edit text-primary mr-2"></i>
                    Sửa Category
                </h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_category">
                    <input type="hidden" id="editCategoryId" name="category_id">
                    
                    <div class="form-group">
                        <label for="editCategoryName">Tên Category <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="editCategoryName" name="category_name" 
                               placeholder="Nhập tên category..." required>
                    </div>
                    
                    <div class="form-group">
                        <label for="editCategoryDescription">Mô tả</label>
                        <textarea class="form-control" id="editCategoryDescription" name="category_description" 
                                  rows="3" placeholder="Nhập mô tả category..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save mr-1"></i>Cập nhật
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Category Form (Hidden) -->
<form id="deleteCategoryForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete_category">
    <input type="hidden" id="deleteCategoryId" name="category_id">
</form>