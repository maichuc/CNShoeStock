/**
 * Fix Bootstrap Dropdown Functionality
 * Đảm bảo dropdown menu hoạt động đúng trong hệ thống
 */

(function($) {
    "use strict";

    // Đợi DOM load xong
    $(document).ready(function() {
        
        // Fix cho tất cả các dropdown
        initializeDropdowns();
        
        // Close dropdown khi click outside
        handleOutsideClick();
    });

    /**
     * Khởi tạo tất cả dropdown
     */
    function initializeDropdowns() {
        $('.dropdown-toggle').each(function() {
            var $toggle = $(this);
            var $menu = $toggle.next('.dropdown-menu');
            
            // Đảm bảo dropdown menu có class đúng
            if (!$menu.length || !$menu.hasClass('dropdown-menu')) {
                $menu = $toggle.siblings('.dropdown-menu').first();
            }
            
            if (!$menu.length) {
                return; // Skip if no menu found
            }
            
            // Bind click event
            $toggle.off('click.dropdownfix').on('click.dropdownfix', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                var wasVisible = $menu.hasClass('show');
                
                // Close all other dropdowns
                $('.dropdown-menu').removeClass('show');
                $('.dropdown-toggle').attr('aria-expanded', 'false');
                
                // Toggle current dropdown (opposite of previous state)
                if (!wasVisible) {
                    $menu.addClass('show');
                    $toggle.attr('aria-expanded', 'true');
                    console.log('Dropdown opened:', $toggle.attr('id') || 'unnamed');
                } else {
                    console.log('Dropdown closed:', $toggle.attr('id') || 'unnamed');
                }
            });
        });
        
        console.log('Initialized', $('.dropdown-toggle').length, 'dropdowns');
    }

    /**
     * Đóng dropdown khi click bên ngoài
     */
    function handleOutsideClick() {
        $(document).on('click.dropdownfix', function(e) {
            // Nếu click không phải trên dropdown
            if (!$(e.target).closest('.dropdown').length) {
                $('.dropdown-menu').removeClass('show');
                $('.dropdown-toggle').attr('aria-expanded', 'false');
            }
        });
        
        // Ngăn dropdown close khi click vào menu items (trừ links)
        $('.dropdown-menu').on('click.dropdownfix', function(e) {
            if (!$(e.target).is('a')) {
                e.stopPropagation();
            }
        });
    }

    // Log khi script loaded
    console.log('Dropdown fix script loaded successfully');

})(jQuery);
