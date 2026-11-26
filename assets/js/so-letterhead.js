// Special Order letterhead: spreads each line to its width on the office's letterhead (in pt):
// the agency name and the rule under it span 285pt, the region line about 283pt. Letter-spacing is
// capped so a short office line isn't stretched apart. Runs after the fonts load, since the widths
// depend on the font the computer has.
(function () {
  var targets = { 'lh-republic': 105, 'lh-name': 285, 'lh-office': 283 };
  function fit() {
    document.querySelectorAll('.so-sheet .lh-text > span').forEach(function (line) {
      var target = targets[line.className] * 4 / 3; // pt → px
      var chars = line.textContent.trim().length;
      if (!target || chars < 2) return;
      line.style.letterSpacing = '';
      var gap = (target - line.getBoundingClientRect().width) / (chars - 1);
      line.style.letterSpacing = Math.max(-0.5, Math.min(gap, 4)) + 'px';
    });
    document.querySelectorAll('.so-sheet .lh-name').forEach(function (name) { name.style.width = '285pt'; });
  }
  (document.fonts ? document.fonts.ready : Promise.resolve()).then(fit);
}());
