/**
 * Backend Lafka plugin scripts
 */

(function ($) {
    "use strict";
    $(document).ready(function () {

        // Import theme options
        var lafka_theme_options_uploader = new plupload.Uploader({
            runtimes: 'html5',

            browse_button: 'lafka_import_options', // you can pass in id...

            url: ajaxurl,
            multipart_params: {
                'action': 'lafka_options_upload'
            },

            filters: {
                max_file_size: '10mb',
                mime_types: [
                    {title: "text files", extensions: "txt"}
                ]
            },
            multi_selection: false,

            init: {
                FilesAdded: function (up, files) {
                    plupload.each(files, function (file) {
                        if (confirm(localise.confirm_import_1 + ' ' + file.name + ' ' + localise.confirm_import_2)) {
                            lafka_theme_options_uploader.start();
                        }
                        return false;
                    });
                },

                FileUploaded: function (up, file, result) {
                    alert(localise.import_success);
                    location.reload(true);
                },

                Error: function (up, err) {
                    alert(localise.upload_error + ' #' + err.code + ': ' + err.message);
                }
            }
        });

        lafka_theme_options_uploader.init();

        // export options
        $(document).on('click', '#lafka_export_options', function() {
            window.location = localise.export_url;
        });

    });
})(window.jQuery);