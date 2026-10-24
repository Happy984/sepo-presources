/* Sepo Resources — media pickers on the document edit screen. */
(function ($) {
  'use strict';
  var t = window.SepoResourcesAdmin || {};

  function picker(opts) {
    var frame = null;
    return function (e) {
      e.preventDefault();
      if (!frame) {
        frame = wp.media({
          title: opts.title,
          button: { text: t.use || 'Use' },
          library: opts.library || {},
          multiple: false
        });
        frame.on('select', function () {
          opts.onSelect(frame.state().get('selection').first().toJSON());
        });
      }
      frame.open();
    };
  }

  $(function () {
    var $file = $('[data-spr-file]');
    if ($file.length) {
      $file.on('click', '[data-spr-file-pick]', picker({
        title: t.chooseFile,
        onSelect: function (a) {
          $file.find('[data-spr-file-id]').val(a.id);
          $file.find('[data-spr-file-name]').text(a.filename || a.title);
          $file.find('[data-spr-file-clear]').prop('hidden', false);
        }
      }));
      $file.on('click', '[data-spr-file-clear]', function (e) {
        e.preventDefault();
        $file.find('[data-spr-file-id]').val('');
        $file.find('[data-spr-file-name]').text(t.none || '');
        $(this).prop('hidden', true);
      });
    }

    var $img = $('[data-spr-image]');
    if ($img.length) {
      $img.on('click', '[data-spr-image-pick], [data-spr-image-preview]', picker({
        title: t.chooseImage,
        library: { type: 'image' },
        onSelect: function (a) {
          var url = (a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url);
          $img.find('[data-spr-image-id]').val(a.id);
          $img.find('[data-spr-image-preview]').css('background-image', 'url("' + url + '")');
          $img.find('[data-spr-image-clear]').prop('hidden', false);
        }
      }));
      $img.on('click', '[data-spr-image-clear]', function (e) {
        e.preventDefault();
        $img.find('[data-spr-image-id]').val('');
        $img.find('[data-spr-image-preview]').css('background-image', '');
        $(this).prop('hidden', true);
      });
    }
  });
})(jQuery);
