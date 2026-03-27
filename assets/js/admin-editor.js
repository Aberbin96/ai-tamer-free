(function($) {
    'use strict';

    $(document).ready(function() {
        // Only run if we are in the block editor or classic editor with the meta box present.
        const $statusContainer = $('#aitamer-ai-status');
        if (!$statusContainer.length) return;

        /**
         * Trigger AI detection via REST API and update the DOM.
         */
        const updateAiStatus = (content) => {
            if (!content) return;

            $statusContainer.css('opacity', '0.5');

            $.ajax({
                url: aitamer_admin.rest_url + 'ai-tamer/v1/detect',
                method: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', aitamer_admin.nonce);
                },
                data: { content: content },
                success: function(response) {
                    $statusContainer.find('.aitamer-score-value').text(response.score + '%');
                    $statusContainer.find('.aitamer-label-value').text(response.label).css('color', response.color);
                    $statusContainer.css('border-left-color', response.color); // Update border color too
                    $statusContainer.css('opacity', '1');
                },
                error: function() {
                    $statusContainer.css('opacity', '1');
                }
            });
        };

        // Debounce function to avoid too many API calls
        let debounceTimer;
        const debounceDetect = (content) => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => updateAiStatus(content), 2000); // 2 seconds delay
        };

        // 1. Gutenberg (Block Editor) Support
        if (window.wp && wp.data && wp.data.select('core/editor')) {
            const { subscribe, select } = wp.data;
            let lastContent = select('core/editor').getEditedPostContent();

            subscribe(() => {
                const currentContent = select('core/editor').getEditedPostContent();
                
                // If content changed significantly (ignoring tiny typing steps for performance)
                if (currentContent !== lastContent && currentContent.length > 50) {
                    lastContent = currentContent;
                    debounceDetect(currentContent);
                }
            });
        }

        // 2. Classic Editor Support
        const setupClassicEditor = () => {
            if (window.tinyMCE && tinyMCE.activeEditor) {
                tinyMCE.activeEditor.on('keyup change', function() {
                    debounceDetect(tinyMCE.activeEditor.getContent());
                });
            } else {
                $('#content').on('keyup change', function() {
                    debounceDetect($(this).val());
                });
            }
        };

        // Initialize classic editor listeners if not in Gutenberg
        if (!window.wp || !wp.data || !wp.data.select('core/editor')) {
            $(window).on('load', setupClassicEditor);
        }

        $('#publish').on('click', function() {
            let content = '';
            if (window.tinyMCE && tinyMCE.activeEditor) {
                content = tinyMCE.activeEditor.getContent();
            } else {
                content = $('#content').val();
            }
            updateAiStatus(content);
        });
    });

})(jQuery);
