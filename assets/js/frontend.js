(function(){
  // Ceník je už v HTML ze serveru. Tady ho jen tiše obnovíme přes REST –
  // kdyby stránka přišla ze starší cache. Když je obsah stejný, DOM se nemění.
  function sig(node){
    return (node.textContent || '').replace(/\s+/g, '');
  }

  function refreshDynamicPricelists(){
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
      url.searchParams.set('_', String(Date.now())); // cache-buster (proxy i prohlížeč)
      fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' })
        .then(function(res){ return res.json(); })
        .then(function(data){
          if (!data || typeof data.html !== 'string' || !data.html) { return; }
          var tmp = document.createElement('div');
          tmp.innerHTML = data.html;
          if (sig(tmp) !== sig(node)) {
            node.innerHTML = data.html;
          }
          node.setAttribute('data-loaded', '1');
        })
        .catch(function(){
          // Serverový obsah necháme – je lepší než chybová hláška.
        });
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', refreshDynamicPricelists);
  } else {
    refreshDynamicPricelists();
  }
})();
