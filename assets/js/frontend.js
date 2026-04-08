(function(){
  function loadDynamicPricelists(){
    var placeholders = document.querySelectorAll('.swpb-dynamic-placeholder');
    if (!placeholders.length || !window.swpbFrontend || !window.fetch) { return; }
    placeholders.forEach(function(node){
      var id = node.getAttribute('data-pricelist');
      if (!id) { return; }
      var category = node.getAttribute('data-category') || '';
      var layout = node.getAttribute('data-layout') || '';
      var url = new URL(window.swpbFrontend.endpoint, window.location.origin);
      url.searchParams.set('id', id);
      if (category && category !== '0') { url.searchParams.set('category', category); }
      if (layout) { url.searchParams.set('layout', layout); }
      fetch(url.toString(), { credentials: 'same-origin' })
        .then(function(res){ return res.json(); })
        .then(function(data){
          if (data && data.html) {
            node.innerHTML = data.html;
            node.setAttribute('data-loaded', '1');
          }
        })
        .catch(function(){
          node.innerHTML = '<div class="swpb-empty">Ceník se nepodařilo načíst.</div>';
        });
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', loadDynamicPricelists);
  } else {
    loadDynamicPricelists();
  }
})();
