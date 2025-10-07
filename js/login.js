/* =========================================================
   Dorozs – Admin front-end JS (upload + lista)
   - Bootstrap 3 kompatibilis
   - Upload wizard (stepper), RTF, dinamikus mezők, súgó
   - Valós mentés: /admin/save_catalog.php (SQLite backend)
   - Lista oldal: /admin/api/products.php (JSON)
   ========================================================= */

$(function(){

  /* ---------- Segéd: slugify (ékezetek eltávolítása) ---------- */
  function slugify(str){
    if(!str) return '';
    var from = 'ÁÄÂÀÃÅáäâàãåÉËÊÈéëêèÍÏÎÌíïîìÓÖÔÒÕóöôòõÚÜÛÙúüûùÇçŰűŮůŔŕŐőÑñÝýßØøÆæÞþÐð';
    var to   = 'AAAAAAaaaaaaEEEEeeeeIIIIiiiiOOOOOoooooUUUUuuuuCcUuUuRrOoAaPpDd';
    for (var i=0; i<from.length; i++){ str = str.replace(new RegExp(from.charAt(i),'g'), to.charAt(i)); }
    return str.toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'');
  }

  /* ---------- Wizard lépések ---------- */
  var steps = ['#step1','#step2','#step3','#stepSEO','#step4'];
  function idx(){ return steps.findIndex(function(id){ return $(id).hasClass('active'); }); }
  function go(i){
    $('.stepper li:eq('+i+') a').tab('show');
    $('#btnPrev').prop('disabled', i===0);
    $('#btnNext').toggle(i<steps.length-1);
    $('#btnSave').toggle(i===steps.length-1);
  }
  $('#btnPrev').click(function(){ var i=idx(); if(i>0) go(i-1); });
  $('#btnNext').click(function(){
    if(idx()===3){ // Összegzés frissítése (következő az #step4)
      $('#sum_cat_name').text($('#cat_name').val()||'–');
      $('#sum_cat_url').text($('#cat_url_preview').text()||'–');

      var parentText = $('#sub_parent option:selected').text();
      $('#sum_sub_parent').text(parentText||'–');
      $('#sum_sub_name').text($('#sub_name').val()||'–');
      $('#sum_sub_url').text($('#sub_url_preview').text()||'–');

      $('#sum_cl_title').text($('#cl_title').val()||'–');
      $('#sum_cl_url').text($('#cl_url_preview').text()||'–');
      $('#sum_cl_new').html($('#preview_new_badge').is(':visible')?'<span class="badge badge-brand">ÚJ</span>':'–');

      $('#sum_seo_title').text($('#seo_title').val()||'–');
      $('#sum_seo_desc').text($('#seo_desc').val()||'–');
      $('#sum_og_title').text($('#og_title').val()||'–');
      $('#sum_og_desc').text($('#og_desc').val()||'–');
      $('#sum_og_type').text($('#og_type').val()||'–');
      $('#sum_seo_url').text($('#seo_url_preview').text()||'–');
    }
    var i=idx(); if(i<steps.length-1) go(i+1);
  });
  $('.stepper a[data-toggle="tab"]').on('shown.bs.tab', function(e){
    go($('.stepper li').index($(e.target).parent()));
  });
  go(0);

  /* ---------- URL előnézetek ---------- */
  function refreshCat(){
    var cat = slugify($('#cat_name').val());
    $('#cat_url_preview').text('/'+(cat||'-')+'/');
    refreshSub(); refreshCatalog();
  }
  function refreshSub(){
    var cat = $('#sub_parent').val() || slugify($('#cat_name').val());
    var sub = slugify($('#sub_name').val());
    $('#sub_url_preview').text('/'+(cat||'-')+'/'+(sub||'-')+'/');
    refreshCatalog();
  }
  function refreshCatalog(){
    var cat = $('#cl_cat').val() || $('#sub_parent').val() || slugify($('#cat_name').val());
    var sub = $('#cl_sub').val();
    var title = slugify($('#cl_title').val());
    var url = '/'+(cat||'-')+'/'+(sub? sub+'/' : '')+(title||'katalogus')+'/';
    $('#cl_url_preview').text(url);
    $('#seo_url_path').text(url);
    $('#seo_url_preview').html('https://dorozshidraulika.hu<b id="seo_url_path">'+url+'</b>');
  }
  $('#cat_name').on('input blur', refreshCat);
  $('#sub_name, #sub_parent').on('input change blur', refreshSub);
  $('#cl_title, #cl_cat, #cl_sub').on('input change blur', refreshCatalog);
  refreshCat(); refreshSub(); refreshCatalog();

  /* ---------- PDF mezők ---------- */
  var pdfMax = 10;
  $('#btnAddPdf').click(function(){
    var count = $('#pdf_wrap .pdf-row').length;
    if(count>=pdfMax) return;
    $('#pdf_wrap').append(
      '<div class="row pdf-row" style="margin-bottom:8px;">'
      +'<div class="col-sm-6"><input type="text" class="form-control pdf-name" placeholder="Megjelenő név"></div>'
      +'<div class="col-sm-5"><input type="file" class="form-control pdf-file" accept=".pdf,application/pdf"></div>'
      +'<div class="col-sm-1"><button type="button" class="btn btn-link text-danger btnRemovePdf"><i class="fa fa-times"></i></button></div>'
      +'</div>'
    );
  });
  $('#pdf_wrap').on('click','.btnRemovePdf', function(){ $(this).closest('.pdf-row').remove(); updateNewBadge(); });

  /* ---------- Galéria mezők ---------- */
  var galMax = 4;
  $('#btnAddGallery').click(function(){
    var count = $('#gallery_wrap .gallery-row').length;
    if(count>=galMax) return;
    $('#gallery_wrap').append(
      '<div class="row gallery-row" style="margin-bottom:8px;">'
      +'<div class="col-sm-8"><input type="file" class="form-control gallery-input" accept="image/*"></div>'
      +'<div class="col-sm-4"><input type="text" class="form-control" placeholder="Cím / alt"></div>'
      +'</div>'
    );
  });

  /* ---------- ÚJ badge automatikus ---------- */
  function updateNewBadge(){
    var anyPdf = $('#pdf_wrap .pdf-file').filter(function(){ return this.files && this.files.length; }).length > 0;
    var anyImg = $('#gallery_wrap .gallery-input').filter(function(){ return this.files && this.files.length; }).length > 0;
    $('#preview_new_badge').toggle(anyPdf || anyImg);
  }
  $('#pdf_wrap').on('change','.pdf-file',updateNewBadge);
  $('#gallery_wrap').on('change','.gallery-input',updateNewBadge);

  /* ---------- RTF ---------- */
  $('.rtf-toolbar [data-cmd="bold"]').click(function(){ document.execCommand('bold', false, null); });
  $('#btn-ul').click(function(){ document.execCommand('insertUnorderedList', false, null); });

  /* ---------- Mentés – valós beküldés SQLite backendre ---------- */
  function collectFormData(){
    var fd = new FormData();

    // Főkategória
    fd.append('cat_name', $('#cat_name').val() || '');
    fd.append('cat_descr', $('#cat_desc').val() || '');

    // Alkategória
    fd.append('sub_parent', $('#sub_parent').val() || '');   // slug
    fd.append('sub_name', $('#sub_name').val() || '');

    // Katalógus
    fd.append('cl_title', $('#cl_title').val() || '');
    fd.append('cl_short', $('#cl_short').val() || '');
    fd.append('cl_cat', $('#cl_cat').val() || '');
    fd.append('cl_sub', $('#cl_sub').val() || '');
    fd.append('rtf_html', $('#rtf').html() || '');

    // SEO
    fd.append('seo_title', $('#seo_title').val() || '');
    fd.append('seo_desc', $('#seo_desc').val() || '');
    fd.append('og_title', $('#og_title').val() || '');
    fd.append('og_desc', $('#og_desc').val() || '');

    // PDF-ek: nevek + fájlok (pdf-name + pdf-file)
    var pdfNames = [];
    var pdfFiles = [];
    $('#pdf_wrap .pdf-row').each(function(){
      var name = $(this).find('.pdf-name').val() || '';
      var input = $(this).find('.pdf-file')[0];
      var file = input ? input.files[0] : null;
      if (file) {
        pdfNames.push(name);
        pdfFiles.push(file);
      }
    });
    for (var i=0;i<pdfNames.length;i++){ fd.append('pdf_names[]', pdfNames[i]); }
    for (var j=0;j<pdfFiles.length;j++){ fd.append('pdf_files[]', pdfFiles[j]); }

    // Galéria: fájl + alt
    var galFiles = [], galAlts = [];
    $('#gallery_wrap .gallery-row').each(function(){
      var input = $(this).find('.gallery-input')[0];
      var file  = input ? input.files[0] : null;
      var alt   = $(this).find('input[type="text"]').val() || '';
      if (file) {
        galFiles.push(file);
        galAlts.push(alt);
      }
    });
    for (var k=0;k<galFiles.length;k++){ fd.append('gallery_files[]', galFiles[k]); }
    for (var m=0;m<galAlts.length;m++){ fd.append('gallery_alts[]', galAlts[m]); }

    return fd;
  }

  // (külön mentés gombok – most tájékoztató jellegűek)
  $('#saveCategory').off('click').on('click', function(){
    alert('A végleges mentés a Teljes mentés gombbal történik. (Külön főkategória mentés backendje opcionális.)');
  });
  $('#saveSubCategory').off('click').on('click', function(){
    alert('A végleges mentés a Teljes mentés gombbal történik. (Külön alkategória mentés backendje opcionális.)');
  });

  $('#btnSave').off('click').on('click', function(){
    var fd = collectFormData();
    $.ajax({
      url: 'save_catalog.php',
      method: 'POST',
      data: fd,
      processData: false,
      contentType: false,
      success: function(res){
        if (res && res.ok){
          alert('Mentve. URL: ' + res.url);
          window.location.href = 'list.php';
        } else {
          alert('Hiba: ' + (res && res.error ? res.error : 'ismeretlen'));
        }
      },
      error: function(xhr){
        alert('Hálózati hiba: ' + xhr.status);
      }
    });
  });

  /* ---------- Súgó: dinamikus, csak AKTÍV fülre és PONTOS mezőkre ---------- */

  function bestPlacement($el){
    var off = $el.offset(), win = $(window);
    var spaceBelow = (win.scrollTop()+win.height()) - (off.top + $el.outerHeight());
    return spaceBelow < 180 ? 'top' : 'bottom';
  }
  function scrollInto($el, cb){
    $('html, body').animate({scrollTop: Math.max(0, $el.offset().top - 120)}, 250, cb);
  }

  function generateHelpSteps(activeId){
    switch(activeId){
      case 'step1': // Főkategória
        return [
          {el:'#step1 .panel-heading', title:'Főkategória', content:'<ul class="help-bullets"><li>Add meg a főkategória nevét és leírását.</li><li>Az URL előnézet automatikus.</li><li>A piros „Mentés” csak a főkategóriát menti.</li></ul>'},
          {el:'#cat_name_wrap', title:'Név', content:'<ul class="help-bullets"><li>A nyilvános címsorban jelenik meg.</li><li>Slug automatikus.</li></ul>'},
          {el:'#cat_url_wrap', title:'URL előnézet', content:'<ul class="help-bullets"><li>Csak információ.</li></ul>'},
          {el:'#cat_desc_wrap', title:'Leírás', content:'<ul class="help-bullets"><li>Rövid összefoglaló.</li></ul>'},
          {el:'#saveCategory', title:'Mentés', content:'<ul class="help-bullets"><li>Külön mentés a főkategóriához.</li></ul>'}
        ];
      case 'step2': // Alkategória
        return [
          {el:'#step2 .panel-heading', title:'Alkategória', content:'<ul class="help-bullets"><li>Válaszd ki a szülő főkategóriát.</li><li>Adj meg egy alkategória nevet.</li></ul>'},
          {el:'#sub_parent_wrap', title:'Szülő főkategória', content:'<ul class="help-bullets"><li>Meghatározza a megjelenés helyét.</li></ul>'},
          {el:'#sub_name_wrap', title:'Alkategória neve', content:'<ul class="help-bullets"><li>Ebből készül az útvonal.</li></ul>'},
          {el:'#sub_url_wrap', title:'URL előnézet', content:'<ul class="help-bullets"><li>Csak megjelenítés.</li></ul>'},
          {el:'#saveSubCategory', title:'Mentés', content:'<ul class="help-bullets"><li>Külön menthető az alkategória.</li></ul>'}
        ];
      case 'step3': // Katalógus
        return [
          {el:'#step3 .panel-heading', title:'Katalógus', content:'<ul class="help-bullets"><li>PDF-ek és képek feltöltése.</li><li>Új fájloknál az „ÚJ” badge automatikus.</li></ul>'},
          {el:'#cl_title_wrap', title:'Cím', content:'<ul class="help-bullets"><li>A nyilvános cím és az URL része.</li></ul>'},
          {el:'#cl_short_wrap', title:'Rövid leírás', content:'<ul class="help-bullets"><li>Kártyák és listák rövid szövege.</li></ul>'},
          {el:'#cl_selects_wrap', title:'Hovatartozás', content:'<ul class="help-bullets"><li>Válassz fő- és (opcionális) alkategóriát.</li></ul>'},
          {el:'#cl_url_wrap', title:'Katalógus URL', content:'<ul class="help-bullets"><li>A cím + kategóriák alapján készül.</li></ul>'},
          {el:'#rtf_wrap', title:'Leírás (formázható)', content:'<ul class="help-bullets"><li>Félkövér és felsorolás.</li></ul>'},
          {el:'#gallery_wrap_group', title:'Galéria', content:'<ul class="help-bullets"><li>„Kép mező” gombbal bővíthető (max 4).</li></ul>'},
          {el:'#pdf_wrap_group', title:'PDF-ek', content:'<ul class="help-bullets"><li>„PDF hozzáadása” gombbal több fájl.</li><li>Az X-szel törölhető sor.</li></ul>'}
        ];
      case 'stepSEO': // SEO
        return [
          {el:'#stepSEO .panel-heading', title:'SEO', content:'<ul class="help-bullets"><li>Töltsd a Meta Title és Description mezőket.</li><li>Állítsd az Open Graph adatokat.</li></ul>'},
          {el:'#seo_title_wrap', title:'Meta Title', content:'<ul class="help-bullets"><li>~50–60 karakter ajánlott.</li></ul>'},
          {el:'#seo_desc_wrap', title:'Meta Description', content:'<ul class="help-bullets"><li>~150–160 karakter ajánlott.</li></ul>'},
          {el:'#og_title_wrap', title:'OG Title', content:'<ul class="help-bullets"><li>Megosztás címe.</li></ul>'},
          {el:'#og_desc_wrap', title:'OG Description', content:'<ul class="help-bullets"><li>Megosztás leírása.</li></ul>'},
          {el:'#og_type_wrap', title:'OG Type és kép', content:'<ul class="help-bullets"><li>Válassz típust és tölts fel képet.</li></ul>'},
          {el:'#seo_url_wrap', title:'Canonical / OG URL', content:'<ul class="help-bullets"><li>Automatikusan követi a katalógus URL-t.</li></ul>'}
        ];
      case 'step4': // Összegzés
        return [
          {el:'#step4 .panel-heading', title:'Összegzés', content:'<ul class="help-bullets"><li>Ellenőrizd a megadott adatokat.</li><li>A „Mentés” gombbal véglegesítheted.</li></ul>'}
        ];
      default:
        return [];
    }
  }

  var stepHelpIndex=0, currentHelpSteps=[];
  function clearHelp(){ $('.help-highlight').popover('destroy').removeClass('help-highlight'); }
  function endHelp(){ clearHelp(); $('#helpOverlay').hide(); stepHelpIndex=0; }

  function showHelp(i){
    if(i<0 || i>=currentHelpSteps.length){ endHelp(); return; }
    stepHelpIndex=i;
    var s=currentHelpSteps[i];
    var $el=$(s.el);
    if(!$el.length){ endHelp(); return; }

    // görgetés, majd popover
    scrollInto($el, function(){
      $('#helpOverlay').show();
      $el.addClass('help-highlight').popover({
        title:s.title,
        content:s.content+'<div class="help-actions">'
          +(i>0?'<button class="btn btn-xs btn-danger" id="helpPrev">Előző</button> ':'')
          +(i<currentHelpSteps.length-1?'<button class="btn btn-xs btn-danger" id="helpNext">Következő</button> ':'')
          +'<button class="btn btn-xs btn-danger" id="helpEnd">Bezár</button>'
          +'</div>',
        html:true,
        placement: bestPlacement($el),
        trigger:'manual',
        container:'body'
      }).popover('show');
    });
  }

  // Súgó indítása: mindig az aktuális fülhöz generál lépéseket
  $('#helpFab').click(function(){
    if($('#helpOverlay').is(':visible')){ endHelp(); return; }
    var activeId = $('.tab-content .tab-pane.active').attr('id');
    currentHelpSteps = generateHelpSteps(activeId) || [];
    if(currentHelpSteps.length){ showHelp(0); }
  });
  // Súgó gombok
  $(document).on('click','#helpNext',function(){ clearHelp(); showHelp(stepHelpIndex+1); });
  $(document).on('click','#helpPrev',function(){ clearHelp(); showHelp(stepHelpIndex-1); });
  $(document).on('click','#helpEnd',endHelp);
  $('#helpOverlay').click(endHelp);

});


/* =========================================================
   TERMÉKLISTA (admin/list.php)
   - Kategória/alkategória szűrők
   - Kereső, rendezés, törlés (frontenden)
   - Súgó a listához
   ========================================================= */
window.productListInit = function () {

  // --- állapot alapból üres, később töltünk szerverről
  var state = {
    rows: [],
    search: '',
    sortKey: 'name',
    sortDir: 'asc', // asc | desc
    filterCategory: '',
    filterSubcategory: ''
  };

  var $table = $('#productTable');
  var $tbody = $table.find('tbody');
  var $filterCat = $('#filterCategory');
  var $filterSub = $('#filterSubcategory');

  /* ---------- segédek ---------- */
  function escapeHtml(s){ return String(s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
  function unique(list){ var m={}; list.forEach(function(v){ if(v!==undefined && v!==null) m[v]=1; }); return Object.keys(m); }

  function sortRows(rows){
    var k = state.sortKey;
    var dir = state.sortDir === 'asc' ? 1 : -1;
    return rows.sort(function(a,b){
      var va = (a[k]===null||a[k]===undefined)?'':a[k];
      var vb = (b[k]===null||b[k]===undefined)?'':b[k];
      if (typeof va === 'boolean') va = va ? 1 : 0;
      if (typeof vb === 'boolean') vb = vb ? 1 : 0;
      if (typeof va === 'string') va = va.toLowerCase();
      if (typeof vb === 'string') vb = vb.toLowerCase();
      if (va < vb) return -1*dir;
      if (va > vb) return  1*dir;
      return 0;
    });
  }

  function filterRows(rows){
    var q = state.search.trim().toLowerCase();
    var cat = state.filterCategory;
    var sub = state.filterSubcategory;

    return rows.filter(function(r){
      if (cat && r.category !== cat) return false;

      if (sub){
        if (sub === '(nincs alkategória)'){
          if ((r.subcategory||'') !== '') return false;
        } else {
          if (r.subcategory !== sub) return false;
        }
      }

      if (!q) return true;
      return (r.name||'').toLowerCase().indexOf(q) >= 0
          || (r.category||'').toLowerCase().indexOf(q) >= 0
          || (r.subcategory||'').toLowerCase().indexOf(q) >= 0
          || (r.url||'').toLowerCase().indexOf(q) >= 0;
    });
  }

  /* ---------- szűrő opciók ---------- */
  function populateCategoryOptions(){
    var categories = unique(state.rows.map(function(r){ return r.category || ''; })).sort();
    var html = '<option value="">Mind</option>';
    categories.forEach(function(c){
      html += '<option value="'+escapeHtml(c)+'">'+escapeHtml(c)+'</option>';
    });
    $filterCat.html(html);
  }

  function populateSubcategoryOptions(){
    var cat = state.filterCategory;
    var rows = !cat ? state.rows : state.rows.filter(function(r){ return r.category===cat; });
    var subs = unique(rows.map(function(r){ return r.subcategory === '' ? '(nincs alkategória)' : r.subcategory; })).sort();

    var html = '<option value="">Mind</option>';
    subs.forEach(function(s){
      html += '<option value="'+escapeHtml(s)+'">'+escapeHtml(s)+'</option>';
    });
    $filterSub.html(html);

    var optExists = !!$filterSub.find('option[value="'+state.filterSubcategory+'"]').length;
    if (!optExists) state.filterSubcategory = '';
  }

  /* ---------- render ---------- */
  function render(){
    var rows = sortRows(filterRows(state.rows.slice(0)));

    $table.find('th .sort-indicator').removeClass('fa-sort-asc fa-sort-desc fa-sort').addClass('fa-sort');
    $table.find('th.sortable[data-key="'+state.sortKey+'"] .sort-indicator')
          .removeClass('fa-sort').addClass(state.sortDir==='asc'?'fa-sort-asc':'fa-sort-desc');

    var html = rows.map(function(r){
      return '<tr>'
        + '<td>'+ escapeHtml(r.name) +'</td>'
        + '<td>'+ escapeHtml(r.category) +'</td>'
        + '<td>'+ (r.subcategory ? escapeHtml(r.subcategory) : '<span class="text-muted">–</span>') +'</td>'
        + '<td class="hidden-xs"><code>'+ escapeHtml(r.url) +'</code></td>'
        + '<td class="text-center">'+ (r.isNew ? '<span class="badge badge-brand">ÚJ</span>' : '<span class="text-muted">–</span>') +'</td>'
        + '<td class="text-right">'
            + '<a href="upload.php?id='+r.id+'" class="btn btn-xs btn-default" title="Módosítás"><i class="fa fa-pencil"></i></a> '
            + '<button class="btn btn-xs btn-danger js-del" data-id="'+r.id+'" title="Törlés"><i class="fa fa-trash"></i></button>'
          + '</td>'
        +'</tr>';
    }).join('');
    $tbody.html(html || '<tr><td colspan="6" class="text-center text-muted">Nincs találat.</td></tr>');
  }

  /* ---------- események ---------- */
  // kereső
  $('#productSearch').on('input', function(){
    state.search = $(this).val();
    render();
  });
  $('#productSearchClear').on('click', function(){
    $('#productSearch').val('');
    state.search = '';
    render();
    $('#productSearch').focus();
  });

  // rendezés
  $table.on('click', 'th.sortable', function(){
    var key = $(this).data('key');
    if (state.sortKey === key){
      state.sortDir = (state.sortDir==='asc') ? 'desc' : 'asc';
    } else {
      state.sortKey = key;
      state.sortDir = 'asc';
    }
    render();
  });

  // törlés (frontenden, demó)
  $tbody.on('click', '.js-del', function(){
    var id = +$(this).data('id');
    var row = state.rows.find(function(r){return r.id===id;});
    if (!row) return;
    if (confirm('Biztosan törlöd: "'+row.name+'"?')){
      state.rows = state.rows.filter(function(r){return r.id!==id;});
      populateCategoryOptions();
      populateSubcategoryOptions();
      render();
    }
  });

  // kategória/alkategória szűrők
  $filterCat.on('change', function(){
    state.filterCategory = $(this).val();
    populateSubcategoryOptions();
    render();
  });
  $filterSub.on('change', function(){
    state.filterSubcategory = $(this).val();
    render();
  });

  /* ---------- adatbetöltés szerverről ---------- */
  $.getJSON('api/products.php', function(serverData){
    state.rows = (serverData || []).slice(0);
    populateCategoryOptions();
    populateSubcategoryOptions();
    render();
  }).fail(function(){
    state.rows = [];
    populateCategoryOptions();
    populateSubcategoryOptions();
    render();
  });

  /* ===== Súgó a LISTÁHOZ ===== */
  function bestPlacement($el){
    var off=$el.offset(), win=$(window);
    var below=(win.scrollTop()+win.height())-(off.top+$el.outerHeight());
    return below<180?'top':'bottom';
  }
  function scrollInto($el,cb){ $('html,body').animate({scrollTop: Math.max(0,$el.offset().top-120)},250,cb); }
  function listHelpSteps(){
    return [
      {el:'.topbar .pull-left .btn', title:'Új felvétele', content:'<ul class="help-bullets"><li>Új termék űrlap megnyitása.</li></ul>'},
      {el:'#filterCategory', title:'Főkategória szűrő', content:'<ul class="help-bullets"><li>Leszűkíti a listát a választott főkategóriára.</li><li>Az alkategória lista ehhez igazodik.</li></ul>'},
      {el:'#filterSubcategory', title:'Alkategória szűrő', content:'<ul class="help-bullets"><li>Csak a választott főkategóriához tartozó alkategóriákat mutatja.</li><li>Választható „(nincs alkategória)” is.</li></ul>'},
      {el:'#productSearch', title:'Kereső', content:'<ul class="help-bullets"><li>Bármely oszlopra keres.</li><li>Jobb oldali X törli.</li></ul>'},
      {el:'#productTable thead th.sortable:first', title:'Rendezés', content:'<ul class="help-bullets"><li>Kattints a fejlécben az oszlop szerinti rendezéshez.</li><li>Újabb kattintás: növekvő/csökkenő.</li></ul>'},
      {el:'#productTable tbody tr:first .btn-default', title:'Módosítás', content:'<ul class="help-bullets"><li>Megnyitja az űrlapot az adott termékkel.</li></ul>'},
      {el:'#productTable tbody tr:first .btn-danger', title:'Törlés', content:'<ul class="help-bullets"><li>Végleges törlés megerősítéssel.</li></ul>'}
    ];
  }
  function clearHelp(){ $('.help-highlight').popover('destroy').removeClass('help-highlight'); }
  function endHelp(){ clearHelp(); $('#helpOverlay').hide(); }
  function showStep(list,i){
    if(i<0 || i>=list.length){ endHelp(); return; }
    var s=list[i], $el=$(s.el); if(!$el.length){ endHelp(); return; }
    scrollInto($el,function(){
      $('#helpOverlay').show();
      $el.addClass('help-highlight').popover({
        title:s.title,
        content:s.content+'<div class="help-actions">'
          +(i>0?'<button class="btn btn-xs btn-danger js-hprev">Előző</button> ':'')
          +(i<list.length-1?'<button class="btn btn-xs btn-danger js-hnext">Következő</button> ':'')
          +'<button class="btn btn-xs btn-danger js-hend">Bezár</button></div>',
        html:true, placement:bestPlacement($el), trigger:'manual', container:'body'
      }).popover('show');
      $(document).off('click.jshelp').on('click.jshelp','.js-hnext',function(){ clearHelp(); showStep(list,i+1); })
                                     .on('click.jshelp','.js-hprev',function(){ clearHelp(); showStep(list,i-1); })
                                     .on('click.jshelp','.js-hend',function(){ endHelp(); })
                                     .on('click.jshelp','#helpOverlay',function(){ endHelp(); });
    });
  }
  $('#helpFab').off('click').on('click',function(){
    if($('#helpOverlay').is(':visible')){ endHelp(); return; }
    var steps=listHelpSteps(); showStep(steps,0);
  });
};