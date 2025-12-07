<!-- Create Supplier Modal -->
<div class="modal fade" id="createSupplierModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-plus-circle text-success mr-2"></i>
                    Thêm Supplier Mới
                </h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_supplier">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="supplierName">Tên Supplier <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="supplierName" name="supplier_name" 
                                       placeholder="Nhập tên supplier..." required>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="supplierContactName">Người liên hệ</label>
                                <input type="text" class="form-control" id="supplierContactName" name="supplier_contact_name" 
                                       placeholder="Nhập tên người liên hệ...">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="supplierPhone">Số điện thoại</label>
                                <input type="tel" class="form-control" id="supplierPhone" name="supplier_phone" 
                                       placeholder="Nhập số điện thoại...">
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="supplierEmail">Email</label>
                                <input type="email" class="form-control" id="supplierEmail" name="supplier_email" 
                                       placeholder="Nhập email...">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="supplierAddress">Địa chỉ</label>
                        <textarea class="form-control" id="supplierAddress" name="supplier_address" 
                                  rows="2" placeholder="Nhập địa chỉ supplier..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="supplierContactInfo">Thông tin liên hệ khác</label>
                        <textarea class="form-control" id="supplierContactInfo" name="supplier_contact_info" 
                                  rows="3" placeholder="Nhập thông tin liên hệ khác..."></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle mr-2"></i>
                        Supplier này sẽ chỉ khả dụng cho warehouse <strong><?php echo htmlspecialchars($warehouse['name']); ?></strong>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save mr-1"></i>Tạo Supplier
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Supplier Modal -->
<div class="modal fade" id="editSupplierModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-edit text-primary mr-2"></i>
                    Sửa Supplier
                </h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_supplier">
                    <input type="hidden" id="editSupplierId" name="supplier_id">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="editSupplierName">Tên Supplier <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="editSupplierName" name="supplier_name" 
                                       placeholder="Nhập tên supplier..." required>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="editSupplierContactName">Người liên hệ</label>
                                <input type="text" class="form-control" id="editSupplierContactName" name="supplier_contact_name" 
                                       placeholder="Nhập tên người liên hệ...">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="editSupplierPhone">Số điện thoại</label>
                                <input type="tel" class="form-control" id="editSupplierPhone" name="supplier_phone" 
                                       placeholder="Nhập số điện thoại...">
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="editSupplierEmail">Email</label>
                                <input type="email" class="form-control" id="editSupplierEmail" name="supplier_email" 
                                       placeholder="Nhập email...">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="editSupplierAddress">Địa chỉ</label>
                        <textarea class="form-control" id="editSupplierAddress" name="supplier_address" 
                                  rows="2" placeholder="Nhập địa chỉ supplier..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="editSupplierContactInfo">Thông tin liên hệ khác</label>
                        <textarea class="form-control" id="editSupplierContactInfo" name="supplier_contact_info" 
                                  rows="3" placeholder="Nhập thông tin liên hệ khác..."></textarea>
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

<!-- Delete Supplier Form (Hidden) -->
<form id="deleteSupplierForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete_supplier">
    <input type="hidden" id="deleteSupplierId" name="supplier_id">
</form>