(function () {
  window.NovaAmbient = {
    init: function initAmbient(options) {
      var pauseAfterMs = (options && typeof options.pauseAfterMs === 'number') ? options.pauseAfterMs : 7000;
      window.setTimeout(function () {
        document.body.classList.add('ambient-paused');
      }, pauseAfterMs);
    }
  };
})();
