jQuery(function($){
  var $tree = $('#swpb-category-tree');
  if ($tree.length) {
    function saveCategoryOrder() {
      var top = [], sub = [];
      $tree.children('li.swpb-tree-item').each(function(index){
        var id = parseInt($(this).data('id'), 10) || 0;
        if (id) { top.push({id:id, sort_order:index+1}); }
        $(this).find('> ul.swpb-sortable-subtree > li.swpb-tree-item').each(function(subIndex){
          var sid = parseInt($(this).data('id'), 10) || 0;
          if (sid) { sub.push({id:sid, parent_id:id, sort_order:subIndex+1}); }
        });
      });
      $.post(swpbAdmin.ajaxUrl, {action:'swpb_save_category_order', nonce:swpbAdmin.nonce, top:JSON.stringify(top), sub:JSON.stringify(sub)});
    }
    $tree.sortable({handle:'.swpb-handle', items:'> li', update:saveCategoryOrder});
    $tree.find('ul.swpb-sortable-subtree').sortable({handle:'.swpb-handle', items:'> li', update:saveCategoryOrder});
  }

  var $items = $('#swpb-items-sortable');
  if ($items.length && parseInt($items.data('category-id'), 10) > 0) {
    $items.sortable({
      handle: '.swpb-order-cell',
      items: '> tr',
      helper: function(e, tr) {
        var $originals = tr.children();
        var $helper = tr.clone();
        $helper.children().each(function(index){ $(this).width($originals.eq(index).width()); });
        return $helper;
      },
      update: function(){
        var order = [];
        $items.children('tr').each(function(index){
          var id = parseInt($(this).data('id'), 10) || 0;
          if (id) { order.push({id:id, sort_order:index+1}); }
        });
        $.post(swpbAdmin.ajaxUrl, {action:'swpb_save_item_order', nonce:swpbAdmin.nonce, order:JSON.stringify(order)});
      }
    });
  }

  var $itemForm = $('.swpb-item-form');
  if ($itemForm.length) {
    var $pricelistSelect = $itemForm.find('.swpb-item-pricelist');
    var $categorySelect = $itemForm.find('.swpb-item-category');
    var initialSelected = String($categorySelect.data('selected') || '');

    function setCategoryOptions(categories, selectedValue) {
      var placeholder = selectedValue ? swpbAdmin.categoriesChoose : swpbAdmin.categoriesChoose;
      $categorySelect.empty().append($('<option>', { value: '', text: placeholder }));

      if (!categories.length) {
        $categorySelect.append($('<option>', { value: '', text: swpbAdmin.categoriesEmpty, disabled: true }));
        return;
      }

      $.each(categories, function(_, category){
        var $option = $('<option>', { value: String(category.id), text: category.name });
        if (selectedValue && String(category.id) === String(selectedValue)) {
          $option.prop('selected', true);
        }
        $categorySelect.append($option);
      });
    }

    function loadCategories(pricelistId, selectedValue) {
      if (!pricelistId) {
        setCategoryOptions([], '');
        return;
      }

      $.post(swpbAdmin.ajaxUrl, {
        action: 'swpb_get_categories',
        nonce: swpbAdmin.categoriesNonce,
        pricelist_id: pricelistId
      }).done(function(response){
        if (response && response.success && response.data && Array.isArray(response.data.categories)) {
          setCategoryOptions(response.data.categories, selectedValue);
        } else {
          setCategoryOptions([], '');
        }
      }).fail(function(){
        setCategoryOptions([], '');
      });
    }

    $pricelistSelect.on('change', function(){
      loadCategories($(this).val(), '');
    });

    if ($pricelistSelect.val()) {
      loadCategories($pricelistSelect.val(), initialSelected);
    } else {
      setCategoryOptions([], '');
    }
  }
});
