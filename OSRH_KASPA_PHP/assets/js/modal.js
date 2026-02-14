// assets/js/modal.js
// OSRH Modal System - Custom modals to replace browser alerts/confirms
// This file must be loaded in the <head> so it's available to inline scripts

(function() {
    'use strict';
    
    function createModalHTML(options) {
        var type = options.type || 'info';
        var icon = options.icon || '💬';
        var title = options.title || 'Notice';
        var message = options.message || '';
        var isConfirm = options.isConfirm || false;
        var confirmText = options.confirmText || 'OK';
        var cancelText = options.cancelText || 'Cancel';
        var confirmClass = options.confirmClass || 'btn-primary';
        
        var html = '<div class="osrh-modal-overlay" id="osrh-modal-overlay">';
        html += '<div class="osrh-modal modal-' + type + '">';
        html += '<div class="osrh-modal-header">';
        html += '<span class="osrh-modal-icon">' + icon + '</span>';
        html += '<h3 class="osrh-modal-title">' + title + '</h3>';
        html += '</div>';
        html += '<div class="osrh-modal-body">';
        
        // Handle multiline messages
        var lines = message.split('\n');
        lines.forEach(function(line) {
            if (line.trim()) {
                html += '<p>' + line + '</p>';
            }
        });
        
        html += '</div>';
        html += '<div class="osrh-modal-footer">';
        
        if (isConfirm) {
            html += '<button type="button" class="btn btn-outline osrh-modal-cancel">' + cancelText + '</button>';
        }
        html += '<button type="button" class="btn ' + confirmClass + ' osrh-modal-confirm">' + confirmText + '</button>';
        
        html += '</div>';
        html += '</div>';
        html += '</div>';
        
        return html;
    }
    
    function showModal(options) {
        return new Promise(function(resolve) {
            // Remove any existing modal
            var existing = document.getElementById('osrh-modal-overlay');
            if (existing) {
                existing.remove();
            }
            
            // Create and add modal to DOM
            var temp = document.createElement('div');
            temp.innerHTML = createModalHTML(options);
            var overlay = temp.firstChild;
            document.body.appendChild(overlay);
            
            // Trigger animation
            requestAnimationFrame(function() {
                overlay.classList.add('is-visible');
            });
            
            // Handle confirm button
            var confirmBtn = overlay.querySelector('.osrh-modal-confirm');
            var cancelBtn = overlay.querySelector('.osrh-modal-cancel');
            
            function closeModal(result) {
                overlay.classList.remove('is-visible');
                setTimeout(function() {
                    overlay.remove();
                    resolve(result);
                }, 200);
            }
            
            confirmBtn.addEventListener('click', function() {
                closeModal(true);
            });
            
            if (cancelBtn) {
                cancelBtn.addEventListener('click', function() {
                    closeModal(false);
                });
            }
            
            // Close on overlay click (for alerts only)
            if (!options.isConfirm) {
                overlay.addEventListener('click', function(e) {
                    if (e.target === overlay) {
                        closeModal(true);
                    }
                });
            }
            
            // Close on Escape key
            function handleEscape(e) {
                if (e.key === 'Escape') {
                    closeModal(options.isConfirm ? false : true);
                    document.removeEventListener('keydown', handleEscape);
                }
            }
            document.addEventListener('keydown', handleEscape);
            
            // Focus confirm button
            confirmBtn.focus();
        });
    }
    
    // Public API - exposed globally
    window.OSRH = window.OSRH || {};
    
    window.OSRH.alert = function(message, options) {
        options = options || {};
        return showModal({
            type: options.type || 'info',
            icon: options.icon || 'ℹ️',
            title: options.title || 'Notice',
            message: message,
            isConfirm: false,
            confirmText: options.buttonText || 'OK'
        });
    };
    
    window.OSRH.confirm = function(message, options) {
        options = options || {};
        return showModal({
            type: options.type || 'warning',
            icon: options.icon || '⚠️',
            title: options.title || 'Confirm Action',
            message: message,
            isConfirm: true,
            confirmText: options.confirmText || 'Confirm',
            cancelText: options.cancelText || 'Cancel',
            confirmClass: options.confirmClass || 'btn-primary'
        });
    };
    
    // Convenience methods
    window.OSRH.success = function(message, title) {
        return window.OSRH.alert(message, {
            type: 'success',
            icon: '✅',
            title: title || 'Success'
        });
    };
    
    window.OSRH.error = function(message, title) {
        return window.OSRH.alert(message, {
            type: 'danger',
            icon: '❌',
            title: title || 'Error'
        });
    };
    
    window.OSRH.warning = function(message, title) {
        return window.OSRH.alert(message, {
            type: 'warning',
            icon: '⚠️',
            title: title || 'Warning'
        });
    };
    
    window.OSRH.confirmDanger = function(message, options) {
        options = options || {};
        return showModal({
            type: 'danger',
            icon: options.icon || '🗑️',
            title: options.title || 'Confirm Action',
            message: message,
            isConfirm: true,
            confirmText: options.confirmText || 'Delete',
            cancelText: options.cancelText || 'Cancel',
            confirmClass: 'btn-danger'
        });
    };
})();
