document.addEventListener('submit', function (e) {
  var m = e.target.getAttribute('data-confirm');
  if (m && !confirm(m)) e.preventDefault();
});
setTimeout(function () {
  document.querySelectorAll('.flash').forEach(function (f) {
    f.style.transition = '.6s'; f.style.opacity = '0';
    setTimeout(function () { f.remove(); }, 700);
  });
}, 6000);
