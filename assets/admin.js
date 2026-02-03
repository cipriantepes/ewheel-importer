/**
 * Ewheel Importer Admin JavaScript
 */

(function($) {
    'use strict';

    var EwheelImporter = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            $('#ewheel-run-sync').on('click', this.runSync.bind(this));
            $('#ewheel-test-connection').on('click', this.testConnection.bind(this));
        },

        runSync: function(e) {
            e.preventDefault();

            var $button = $('#ewheel-run-sync');
            var $status = $('#ewheel-sync-status');

            $button.prop('disabled', true);
            $status
                .removeClass('success error')
                .addClass('syncing')
                .text(ewheelImporter.strings.syncing);

            $.ajax({
                url: ewheelImporter.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ewheel_run_sync',
                    nonce: ewheelImporter.nonce
                },
                success: function(response) {
                    $button.prop('disabled', false);

                    if (response.success) {
                        $status
                            .removeClass('syncing error')
                            .addClass('success')
                            .text(response.data.message);

                        // Reload page after 2 seconds to show updated last sync time
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        $status
                            .removeClass('syncing success')
                            .addClass('error')
                            .text(ewheelImporter.strings.error + ' ' + response.data.message);
                    }
                },
                error: function(xhr, status, error) {
                    $button.prop('disabled', false);
                    $status
                        .removeClass('syncing success')
                        .addClass('error')
                        .text(ewheelImporter.strings.error + ' ' + error);
                }
            });
        },

        testConnection: function(e) {
            e.preventDefault();

            var $button = $('#ewheel-test-connection');
            var $status = $('#ewheel-connection-status');

            $button.prop('disabled', true);
            $status
                .removeClass('success error')
                .addClass('testing')
                .text(ewheelImporter.strings.testing);

            $.ajax({
                url: ewheelImporter.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ewheel_test_connection',
                    nonce: ewheelImporter.nonce
                },
                success: function(response) {
                    $button.prop('disabled', false);

                    if (response.success) {
                        $status
                            .removeClass('testing error')
                            .addClass('success')
                            .text(ewheelImporter.strings.connected);
                    } else {
                        $status
                            .removeClass('testing success')
                            .addClass('error')
                            .text(ewheelImporter.strings.connFailed + ' ' + response.data.message);
                    }
                },
                error: function(xhr, status, error) {
                    $button.prop('disabled', false);
                    $status
                        .removeClass('testing success')
                        .addClass('error')
                        .text(ewheelImporter.strings.connFailed + ' ' + error);
                }
            });
        }
    };

    $(document).ready(function() {
        EwheelImporter.init();
    });

})(jQuery);
