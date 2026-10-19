/* Reviews: notes how long the page was open when a review form is sent, for the time check against spam on cached pages. */
(function () {
  var t = Date.now();
  document.querySelectorAll('[data-reviews-opened]').forEach(function (i) {
    var f = i.form;
    if (f && !f.dataset.reviewsTimed) {
      f.dataset.reviewsTimed = 1;
      f.addEventListener('submit', function () {
        f.querySelectorAll('[data-reviews-opened]').forEach(function (x) {
          x.value = String(Math.round((Date.now() - t) / 1000));
        });
      });
    }
  });
})();
