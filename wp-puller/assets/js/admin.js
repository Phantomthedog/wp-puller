/**
 * WP Puller Admin JavaScript
 *
 * @package WP_Puller
 * @since 1.0.0
 */

(function($) {
    'use strict';

    var WPPuller = {
        lastDryRunResult: null,

        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            $('#wp-puller-settings-form').on('submit', this.saveSettings.bind(this));
            $('#wp-puller-test-connection').on('click', this.testConnection.bind(this));
            $('#wp-puller-check-updates').on('click', this.checkUpdates.bind(this));
            $('#wp-puller-update-now').on('click', this.updateTheme.bind(this));
            $('#wp-puller-regenerate-secret').on('click', this.regenerateSecret.bind(this));
            $('#wp-puller-clear-logs').on('click', this.clearLogs.bind(this));
            $(document).on('click', '#wp-puller-static-dry-run', this.staticDryRun.bind(this));
            $(document).on('click', '#wp-puller-static-deploy', this.staticDeploy.bind(this));
            $(document).on('click', '.wp-puller-static-rollback', this.staticRollback.bind(this));

            $(document).on('click', '.wp-puller-restore-backup', this.restoreBackup.bind(this));
            $(document).on('click', '.wp-puller-delete-backup', this.deleteBackup.bind(this));
            $(document).on('click', '.wp-puller-copy-btn', this.copyToClipboard.bind(this));
        },

        saveSettings: function(e) {
            e.preventDefault();

            var $form = $(e.currentTarget);
            var $btn = $form.find('[type="submit"]');

            this.setLoading($btn, true);

            $.ajax({
                url: wpPuller.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_puller_save_settings',
                    nonce: wpPuller.nonce,
                    repo_url: $('#wp-puller-repo-url').val(),
                    branch: $('#wp-puller-branch').val(),
                    theme_path: $('#wp-puller-theme-path').val(),
                    pat: $('#wp-puller-pat').val(),
                    auto_update: $('#wp-puller-auto-update').is(':checked') ? 'true' : 'false',
                    backup_count: $('#wp-puller-backup-count').val()
                },
                success: function(response) {
                    if (response.success) {
                        WPPuller.showNotice(response.data.message, 'success');
                    } else {
                        WPPuller.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    WPPuller.showNotice(wpPuller.strings.error, 'error');
                },
                complete: function() {
                    WPPuller.setLoading($btn, false);
                }
            });
        },

        testConnection: function(e) {
            var $btn = $(e.currentTarget);
            var repoUrl = $('#wp-puller-repo-url').val();

            if (!repoUrl) {
                this.showNotice('Please enter a repository URL.', 'error');
                return;
            }

            this.setLoading($btn, true);

            $.ajax({
                url: wpPuller.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_puller_test_connection',
                    nonce: wpPuller.nonce,
                    repo_url: repoUrl
                },
                success: function(response) {
                    if (response.success) {
                        var msg = wpPuller.strings.connected;
                        if (response.data.repo) {
                            msg += ' Repository: ' + response.data.repo.full_name;
                            if (response.data.repo.private) {
                                msg += ' (Private)';
                            }

                            if (response.data.repo.default_branch && !$('#wp-puller-branch').val()) {
                                $('#wp-puller-branch').val(response.data.repo.default_branch);
                            }
                        }
                        WPPuller.showNotice(msg, 'success');
                    } else {
                        WPPuller.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    WPPuller.showNotice(wpPuller.strings.error, 'error');
                },
                complete: function() {
                    WPPuller.setLoading($btn, false);
                }
            });
        },

        checkUpdates: function(e) {
            var $btn = $(e.currentTarget);
            var $result = $('#wp-puller-update-result');

            this.setLoading($btn, true);
            $result.hide();

            $.ajax({
                url: wpPuller.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_puller_check_updates',
                    nonce: wpPuller.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data;
                        var html = '';

                        if (data.is_new_setup) {
                            html = '<p><strong>Ready to install.</strong> Click "Update Now" to pull the theme from GitHub.</p>';
                            $result.removeClass('has-update no-update').addClass('has-update');
                        } else if (data.update_available) {
                            html = '<p><strong>Update available!</strong></p>';
                            html += '<p>Current: <code>' + data.current_commit.substring(0, 7) + '</code></p>';
                            html += '<p>Latest: <code>' + data.latest_commit.short_sha + '</code>';
                            if (data.latest_commit.message) {
                                html += ' - ' + WPPuller.escapeHtml(data.latest_commit.message.substring(0, 60));
                            }
                            html += '</p>';
                            $result.removeClass('has-update no-update').addClass('has-update');
                        } else {
                            html = '<p><strong>Theme is up to date.</strong></p>';
                            html += '<p>Current commit: <code>' + data.latest_commit.short_sha + '</code></p>';
                            $result.removeClass('has-update no-update').addClass('no-update');
                        }

                        $result.html(html).show();
                        $('#last-check').text('just now');
                    } else {
                        WPPuller.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    WPPuller.showNotice(wpPuller.strings.error, 'error');
                },
                complete: function() {
                    WPPuller.setLoading($btn, false);
                }
            });
        },

        updateTheme: function(e) {
            var $btn = $(e.currentTarget);

            this.setLoading($btn, true);

            $.ajax({
                url: wpPuller.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_puller_update_theme',
                    nonce: wpPuller.nonce
                },
                success: function(response) {
                    if (response.success) {
                        WPPuller.showNotice(wpPuller.strings.updated, 'success');

                        if (response.data.status) {
                            $('#current-commit').text(response.data.status.short_commit || '-');
                        }

                        $('#wp-puller-update-result').hide();

                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        WPPuller.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    WPPuller.showNotice(wpPuller.strings.error, 'error');
                },
                complete: function() {
                    WPPuller.setLoading($btn, false);
                }
            });
        },

        restoreBackup: function(e) {
            var $btn = $(e.currentTarget);
            var backupName = $btn.data('name');

            if (!confirm(wpPuller.strings.confirmRestore)) {
                return;
            }

            this.setLoading($btn, true);

            $.ajax({
                url: wpPuller.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_puller_restore_backup',
                    nonce: wpPuller.nonce,
                    backup_name: backupName
                },
                success: function(response) {
                    if (response.success) {
                        WPPuller.showNotice(wpPuller.strings.restored, 'success');
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        WPPuller.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    WPPuller.showNotice(wpPuller.strings.error, 'error');
                },
                complete: function() {
                    WPPuller.setLoading($btn, false);
                }
            });
        },

        deleteBackup: function(e) {
            var $btn = $(e.currentTarget);
            var backupName = $btn.data('name');

            if (!confirm(wpPuller.strings.confirmDelete)) {
                return;
            }

            this.setLoading($btn, true);

            $.ajax({
                url: wpPuller.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_puller_delete_backup',
                    nonce: wpPuller.nonce,
                    backup_name: backupName
                },
                success: function(response) {
                    if (response.success) {
                        $btn.closest('.wp-puller-backup-item').fadeOut(function() {
                            $(this).remove();

                            if ($('#wp-puller-backup-list li').length === 0) {
                                $('#wp-puller-backup-list').replaceWith(
                                    '<p class="wp-puller-empty">No backups yet. A backup is created automatically before each update.</p>'
                                );
                            }
                        });
                        WPPuller.showNotice(wpPuller.strings.deleted, 'success');
                    } else {
                        WPPuller.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    WPPuller.showNotice(wpPuller.strings.error, 'error');
                },
                complete: function() {
                    WPPuller.setLoading($btn, false);
                }
            });
        },

        regenerateSecret: function(e) {
            var $btn = $(e.currentTarget);

            if (!confirm(wpPuller.strings.confirmRegenerate)) {
                return;
            }

            this.setLoading($btn, true);

            $.ajax({
                url: wpPuller.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_puller_regenerate_secret',
                    nonce: wpPuller.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#webhook-secret').val(response.data.secret);
                        WPPuller.showNotice(wpPuller.strings.regenerated, 'success');
                    } else {
                        WPPuller.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    WPPuller.showNotice(wpPuller.strings.error, 'error');
                },
                complete: function() {
                    WPPuller.setLoading($btn, false);
                }
            });
        },

        clearLogs: function(e) {
            var $btn = $(e.currentTarget);

            this.setLoading($btn, true);

            $.ajax({
                url: wpPuller.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_puller_clear_logs',
                    nonce: wpPuller.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#wp-puller-log-list').replaceWith(
                            '<p class="wp-puller-empty">No activity recorded yet.</p>'
                        );
                        $btn.remove();
                    } else {
                        WPPuller.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    WPPuller.showNotice(wpPuller.strings.error, 'error');
                },
                complete: function() {
                    WPPuller.setLoading($btn, false);
                }
            });
        },

        staticDryRun: function(e) {
            var $btn = $(e.currentTarget);
            var sourcePath = $('#wp-puller-static-source').val();

            if (!sourcePath) {
                this.showNotice('Please enter a source path.', 'error');
                return;
            }

            this.setLoading($btn, true);

            var $result = $('#wp-puller-static-result');
            $result.hide().html('');

            $.ajax({
                url: wpPuller.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_puller_static_dry_run',
                    nonce: wpPuller.nonce,
                    source_path: sourcePath
                },
                success: function(response) {
                    if (response.success) {
                        WPPuller.lastDryRunResult = response.data;
                        WPPuller.renderStaticDryRunResult(response.data, $result);
                        $('#wp-puller-static-deploy').prop('disabled', false);
                    } else {
                        WPPuller.lastDryRunResult = null;
                        WPPuller.showNotice(response.data.message, 'error');
                        $('#wp-puller-static-deploy').prop('disabled', true);
                    }
                },
                error: function() {
                    WPPuller.showNotice(wpPuller.strings.error, 'error');
                },
                complete: function() {
                    WPPuller.setLoading($btn, false);
                }
            });
        },

        renderStaticDryRunResult: function(data, $container) {
            var html = '<div class="wp-puller-dry-run-summary">';

            html += '<h4>Dry-Run Report</h4>';
            html += '<ul>';
            var total = (data.summary.allowed_count || 0) + (data.summary.blocked_count || 0) + (data.summary.skipped_count || 0);
            html += '<li><strong>Total files scanned:</strong> ' + total + '</li>';
            html += '<li><strong>Allowed to deploy:</strong> <span class="wp-puller-count-allowed">' + (data.summary.allowed_count || 0) + '</span></li>';
            html += '<li><strong>Blocked:</strong> <span class="wp-puller-count-blocked">' + (data.summary.blocked_count || 0) + '</span></li>';
            html += '<li><strong>Skipped:</strong> <span class="wp-puller-count-skipped">' + (data.summary.skipped_count || 0) + '</span></li>';
            html += '<li><strong>Total size:</strong> ' + this.escapeHtml(data.summary.total_size_fmt || '-') + '</li>';
            html += '</ul>';
            html += '</div>';

            if (data.warnings && data.warnings.length) {
                html += '<div class="wp-puller-dry-run-warnings">';
                html += '<h4>Warnings</h4>';
                html += '<ul>';
                for (var i = 0; i < data.warnings.length; i++) {
                    html += '<li>' + this.escapeHtml(data.warnings[i]) + '</li>';
                }
                html += '</ul>';
                html += '</div>';
            }

            if (data.allowed && data.allowed.length) {
                html += '<div class="wp-puller-dry-run-section">';
                html += '<h4>Allowed Files (' + data.allowed.length + ')</h4>';
                html += '<table class="wp-puller-dry-run-table"><thead><tr><th>File</th><th>Action</th><th>Size</th></tr></thead><tbody>';
                for (var j = 0; j < data.allowed.length; j++) {
                    var f = data.allowed[j];
                    html += '<tr>';
                    html += '<td><code>' + this.escapeHtml(f.source) + '</code></td>';
                    html += '<td><span class="wp-puller-action wp-puller-action-' + this.escapeHtml(f.action) + '">' + this.escapeHtml(f.action) + '</span></td>';
                    html += '<td>' + this.escapeHtml(f.size_fmt || '-') + '</td>';
                    html += '</tr>';
                }
                html += '</tbody></table>';
                html += '</div>';
            }

            if (data.blocked && data.blocked.length) {
                html += '<div class="wp-puller-dry-run-section">';
                html += '<h4>Blocked Files (' + data.blocked.length + ')</h4>';
                html += '<table class="wp-puller-dry-run-table"><thead><tr><th>File</th><th>Reason</th></tr></thead><tbody>';
                for (var k = 0; k < data.blocked.length; k++) {
                    var b = data.blocked[k];
                    html += '<tr>';
                    html += '<td><code>' + this.escapeHtml(b.source) + '</code></td>';
                    html += '<td>' + this.escapeHtml(b.reason) + '</td>';
                    html += '</tr>';
                }
                html += '</tbody></table>';
                html += '</div>';
            }

            $container.html(html).show();

            $('html, body').animate({
                scrollTop: $container.offset().top - 50
            }, 300);
        },

        staticDeploy: function(e) {
            var $btn = $(e.currentTarget);
            var sourcePath = $('#wp-puller-static-source').val();

            if (!sourcePath) {
                this.showNotice('Please enter a source path.', 'error');
                return;
            }

            if (!WPPuller.lastDryRunResult || !WPPuller.lastDryRunResult.summary || WPPuller.lastDryRunResult.summary.allowed_count < 1) {
                this.showNotice('Please run a dry-run preview first.', 'error');
                return;
            }

            var total = WPPuller.lastDryRunResult.summary.allowed_count || 0;
            var replaceCount = 0;
            var addCount = 0;
            if (WPPuller.lastDryRunResult.allowed) {
                for (var i = 0; i < WPPuller.lastDryRunResult.allowed.length; i++) {
                    if (WPPuller.lastDryRunResult.allowed[i].action === 'replace') {
                        replaceCount++;
                    } else {
                        addCount++;
                    }
                }
            }

            var confirmMsg = 'This will:\n';
            confirmMsg += '• Add: ' + addCount + ' new files\n';
            confirmMsg += '• Replace: ' + replaceCount + ' existing files\n';
            confirmMsg += '• Backup: ' + replaceCount + ' files before overwrite\n';
            confirmMsg += '• Total: ' + total + ' files\n\n';
            confirmMsg += 'A rollback point will be created.\n\nContinue?';

            if (!confirm(confirmMsg)) {
                return;
            }

            this.setLoading($btn, true);

            var $result = $('#wp-puller-deploy-result');
            $result.hide().html('');

            $.ajax({
                url: wpPuller.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_puller_static_deploy',
                    nonce: wpPuller.nonce,
                    source_path: sourcePath
                },
                success: function(response) {
                    if (response.success) {
                        WPPuller.renderStaticDeployResult(response.data, $result);
                        WPPuller.showNotice('Static deploy completed successfully.', 'success');
                    } else {
                        var msg = response.data.message || 'Deploy failed.';
                        if (response.data.rollback && response.data.rollback.restored) {
                            msg += ' Rollback restored ' + response.data.rollback.restored.length + ' files.';
                        }
                        WPPuller.showNotice(msg, 'error');
                        WPPuller.renderStaticDeployResult(response.data, $result);
                    }
                },
                error: function() {
                    WPPuller.showNotice(wpPuller.strings.error, 'error');
                },
                complete: function() {
                    WPPuller.setLoading($btn, false);
                }
            });
        },

        renderStaticDeployResult: function(data, $container) {
            var html = '<div class="wp-puller-deploy-manifest">';

            if (data.success) {
                html += '<h4>Deploy Complete ✅</h4>';
            } else {
                html += '<h4>Deploy Failed ❌</h4>';
            }

            html += '<ul>';
            html += '<li><strong>Added:</strong> ' + (data.added ? data.added.length : 0) + '</li>';
            html += '<li><strong>Replaced:</strong> ' + (data.replaced ? data.replaced.length : 0) + '</li>';
            html += '<li><strong>Failed:</strong> ' + (data.failed ? data.failed.length : 0) + '</li>';
            if (data.backup_id) {
                html += '<li><strong>Backup ID:</strong> <code>' + this.escapeHtml(data.backup_id) + '</code></li>';
            }
            if (data.total_size_fmt) {
                html += '<li><strong>Total size:</strong> ' + this.escapeHtml(data.total_size_fmt) + '</li>';
            }
            html += '</ul>';

            if (data.failed && data.failed.length) {
                html += '<div class="wp-puller-deploy-failures">';
                html += '<h5>Failures</h5><ul>';
                for (var f = 0; f < data.failed.length; f++) {
                    html += '<li><code>' + this.escapeHtml(data.failed[f].file) + '</code> — ' + this.escapeHtml(data.failed[f].reason) + '</li>';
                }
                html += '</ul></div>';
            }

            if (data.rollback) {
                html += '<div class="wp-puller-deploy-rollback">';
                html += '<h5>Rollback Result</h5><ul>';
                html += '<li>Restored: ' + (data.rollback.restored ? data.rollback.restored.length : 0) + '</li>';
                html += '<li>Removed: ' + (data.rollback.removed ? data.rollback.removed.length : 0) + '</li>';
                html += '</ul></div>';
            }

            html += '</div>';
            $container.html(html).show();

            $('html, body').animate({
                scrollTop: $container.offset().top - 50
            }, 300);
        },

        staticRollback: function(e) {
            var $btn = $(e.currentTarget);
            var backupId = $btn.data('backup-id');

            if (!backupId) {
                this.showNotice('Invalid backup ID.', 'error');
                return;
            }

            if (!confirm(wpPuller.strings.confirmRollback || 'Rollback to this backup point?')) {
                return;
            }

            this.setLoading($btn, true);

            $.ajax({
                url: wpPuller.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_puller_static_rollback',
                    nonce: wpPuller.nonce,
                    backup_id: backupId
                },
                success: function(response) {
                    if (response.success) {
                        WPPuller.showNotice(
                            'Rollback complete. Restored ' + (response.data.count || 0) + ' files.',
                            'success'
                        );
                    } else {
                        WPPuller.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    WPPuller.showNotice(wpPuller.strings.error, 'error');
                },
                complete: function() {
                    WPPuller.setLoading($btn, false);
                }
            });
        },

        copyToClipboard: function(e) {
            var $btn = $(e.currentTarget);
            var inputId = $btn.data('copy');
            var $input = $('#' + inputId);

            $input.select();

            try {
                document.execCommand('copy');
                $btn.find('.dashicons').removeClass('dashicons-clipboard').addClass('dashicons-yes');

                setTimeout(function() {
                    $btn.find('.dashicons').removeClass('dashicons-yes').addClass('dashicons-clipboard');
                }, 1500);
            } catch (err) {
                // Fallback for older browsers
                window.prompt('Copy to clipboard:', $input.val());
            }
        },

        setLoading: function($btn, loading) {
            if (loading) {
                $btn.addClass('wp-puller-btn-loading').prop('disabled', true);
            } else {
                $btn.removeClass('wp-puller-btn-loading').prop('disabled', false);
            }
        },

        showNotice: function(message, type) {
            var $notice = $('#wp-puller-notice');
            var className = 'notice-' + (type || 'info');

            $notice
                .removeClass('notice-success notice-error notice-info')
                .addClass(className)
                .html(this.escapeHtml(message))
                .fadeIn();

            setTimeout(function() {
                $notice.fadeOut();
            }, 5000);

            $('html, body').animate({
                scrollTop: $('.wp-puller-wrap').offset().top - 50
            }, 300);
        },

        escapeHtml: function(str) {
            if (!str) return '';
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }
    };

    $(document).ready(function() {
        WPPuller.init();
    });

})(jQuery);
