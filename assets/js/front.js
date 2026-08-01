(function($){
  var hrimSpinnerTimer=null;
  var hrimSuppressUnloadSpinner=false;

  function hrimShowSpinner(label){
    var $spinner=$('#hrimGlobalSpinner');
    if(!$spinner.length)return;
    window.clearTimeout(hrimSpinnerTimer);
    if(label)$spinner.find('span').text(label);
    $spinner.addClass('is-active').attr('aria-hidden','false');
    // Safety reset: a failed download, blocked navigation, or browser cancellation
    // must never leave the entire application permanently covered.
    hrimSpinnerTimer=window.setTimeout(hrimHideSpinner,30000);
  }
  function hrimHideSpinner(){
    window.clearTimeout(hrimSpinnerTimer);
    hrimSpinnerTimer=null;
    $('#hrimGlobalSpinner').removeClass('is-active is-visible').attr('aria-hidden','true').find('span').text('Loading...');
  }
  function hrimIsDownloadLink($link,href){
    return $link.is('[download],.hrim-template-download,[data-no-spinner]') ||
      /(?:^|[?&])action=hrim_download_/i.test(href) ||
      /\.(?:csv|xlsx?|pdf|zip)(?:$|[?#])/i.test(href);
  }
  window.hrimShowSpinner=hrimShowSpinner;
  window.hrimHideSpinner=hrimHideSpinner;

  // v3.15.96: save/update/delete records without refreshing the complete application shell.
  // WordPress admin-post handlers may continue using their existing redirects; the
  // redirected page is fetched in the background and only the main content is replaced.
  function hrimCanAjaxSubmit($form){
    if(!$form.length || $form.attr('target')==='_blank' || $form.is('[data-full-refresh],[data-no-ajax]'))return false;
    var method=String($form.attr('method')||'get').toLowerCase();
    var actionUrl=String($form.attr('action')||window.location.href);
    if(method!=='post' || actionUrl.indexOf('admin-post.php')===-1)return false;
    var actionName=String($form.find('input[name="action"]').val()||'');
    // Downloads, exports, authentication and repair operations must retain normal browser behaviour.
    if(/(?:download|export|login|logout|repair|backup|restore)/i.test(actionName))return false;
    return true;
  }
  function hrimReplaceMainContent(html,finalUrl){
    var parsed=new DOMParser().parseFromString(html,'text/html');
    var incoming=parsed.querySelector('.hrim-content');
    var current=document.querySelector('.hrim-content');
    if(!incoming||!current)return false;
    current.innerHTML=incoming.innerHTML;
    var incomingTitle=parsed.querySelector('.hrim-topbar h1');
    var currentTitle=document.querySelector('.hrim-topbar h1');
    if(incomingTitle&&currentTitle)currentTitle.textContent=incomingTitle.textContent;
    if(parsed.title)document.title=parsed.title;
    if(finalUrl){
      try{window.history.replaceState({},'',finalUrl);}catch(e){}
    }
    // Ensure the newly returned notice is visible and the modal/list starts at its top.
    var main=document.querySelector('.hrim-main');
    if(main&&typeof main.scrollTo==='function')main.scrollTo({top:0,behavior:'smooth'});
    document.dispatchEvent(new CustomEvent('hrim:content-updated'));
    return true;
  }
  $(document).on('submit','form',function(e){
    var $form=$(this);
    if(e.isDefaultPrevented()||!hrimCanAjaxSubmit($form))return;
    e.preventDefault();
    var submitter=e.originalEvent&&e.originalEvent.submitter?e.originalEvent.submitter:null;
    var $button=submitter?$(submitter):$form.find('button[type="submit"],button:not([type]),input[type="submit"]').filter(':visible').first();
    var originalText=$button.is('input')?$button.val():$button.html();
    $button.prop('disabled',true).attr('aria-busy','true');
    hrimShowSpinner($form.attr('data-loading-text')||'Saving...');
    var data=new FormData(this);
    if(submitter&&submitter.name&&!data.has(submitter.name))data.append(submitter.name,submitter.value||'');
    fetch($form.attr('action')||window.location.href,{
      method:'POST',
      body:data,
      credentials:'same-origin',
      redirect:'follow',
      headers:{'X-Requested-With':'XMLHttpRequest'}
    }).then(function(response){
      if(!response.ok)throw new Error('Request failed with status '+response.status);
      return response.text().then(function(text){return {text:text,url:response.url};});
    }).then(function(result){
      if(!hrimReplaceMainContent(result.text,result.url)){
        window.location.href=result.url||window.location.href;
        return;
      }
      hrimHideSpinner();
    }).catch(function(){
      // Safe fallback: submit normally if AJAX is blocked by hosting/security rules.
      hrimHideSpinner();
      $form.attr('data-no-ajax','1');
      if($button.length){
        $button.prop('disabled',false).removeAttr('aria-busy');
        if($button.is('input'))$button.val(originalText);else $button.html(originalText);
      }
      $form.get(0).submit();
    });
  });
  window.addEventListener('pageshow',hrimHideSpinner);
  window.addEventListener('focus',function(){
    // File downloads keep the current page open, so clear any stale overlay.
    if(hrimSuppressUnloadSpinner)window.setTimeout(hrimHideSpinner,100);
  });
  document.addEventListener('visibilitychange',function(){
    if(!document.hidden && hrimSuppressUnloadSpinner)hrimHideSpinner();
  });
  window.addEventListener('beforeunload',function(){
    if(hrimSuppressUnloadSpinner){
      hrimSuppressUnloadSpinner=false;
      return;
    }
    hrimShowSpinner();
  });
  $(document).ajaxStart(function(){hrimShowSpinner();});
  $(document).ajaxStop(hrimHideSpinner);
  $(document).ajaxError(hrimHideSpinner);
  $(document).on('submit','form',function(e){
    if(e.isDefaultPrevented())return;
    var $form=$(this);
    if($form.is('[data-no-spinner]')){
      var $button=$form.find('button[type="submit"],button:not([type]),input[type="submit"]').filter(':visible').first();
      if($button.length){
        window.setTimeout(function(){$button.prop('disabled',true).attr('aria-busy','true');},0);
      }
      return;
    }
    if($form.attr('target')==='_blank')return;
    hrimShowSpinner($form.attr('data-loading-text')||'Please wait...');
  });
  $(document).on('click','a[href]',function(e){
    if(e.isDefaultPrevented()||e.which>1||e.metaKey||e.ctrlKey||e.shiftKey||e.altKey)return;
    var $link=$(this),href=$link.attr('href')||'';
    if(!href||href.charAt(0)==='#'||$link.attr('target')==='_blank'||href.indexOf('javascript:')===0||href.indexOf('mailto:')===0||href.indexOf('tel:')===0)return;
    if(hrimIsDownloadLink($link,href)){
      hrimSuppressUnloadSpinner=true;
      hrimHideSpinner();
      window.setTimeout(function(){hrimSuppressUnloadSpinner=false;hrimHideSpinner();},1500);
      return;
    }
    if(href.indexOf(window.location.origin)===0||href.charAt(0)==='/')hrimShowSpinner();
  });
  window.addEventListener('pageshow',function(e){if(e.persisted){window.location.reload();}});
  try{if(window.performance&&performance.getEntriesByType){var nav=performance.getEntriesByType('navigation')[0];if(nav&&nav.type==='back_forward'){window.location.reload();}}}catch(e){}
  $(document).on('click','.hrim-menu-toggle',function(){$('#hrimSidebar').toggleClass('open');});
  $(document).on('click','.hrim-sidebar-mobile-close',function(){$('#hrimSidebar').removeClass('open');});

  // v3.15.24: expand the sidebar section that contains the current view; keep all others collapsed.
  $('.hrim-nav-group').each(function(){
    var $group=$(this);
    var isCurrent=$group.find('.hrim-nav-group-items > a.active').length>0;
    $group.toggleClass('is-open',isCurrent);
    $group.children('.hrim-nav-group-toggle').attr('aria-expanded',isCurrent?'true':'false');
    $group.children('.hrim-nav-group-items').prop('hidden',!isCurrent);
    try{window.localStorage.removeItem('roomscore_nav_group_'+($group.data('group')||''));}catch(e){}
  });
  $(document).on('click','.hrim-nav-group-toggle',function(){
    var $button=$(this);
    var $group=$button.closest('.hrim-nav-group');
    var $items=$group.children('.hrim-nav-group-items');
    var open=!$group.hasClass('is-open');
    $group.toggleClass('is-open',open);
    $button.attr('aria-expanded',open?'true':'false');
    $items.prop('hidden',!open);
  });

  // v3.14.61: preserve the actual scrollable sidebar navigation position.
  (function(){
    var $nav=$('#hrimSidebar .hrim-sidebar-nav');
    if(!$nav.length)return;
    var scrollKey='roomscore_sidebar_nav_scroll_top';
    var saveTimer=null;
    function saveSidebarScroll(){
      try{window.sessionStorage.setItem(scrollKey,String(Math.max(0,$nav.scrollTop()||0)));}catch(e){}
    }
    function restoreSidebarScroll(){
      var saved=null;
      try{saved=window.sessionStorage.getItem(scrollKey);}catch(e){}
      if(saved===null||saved==='')return;
      var top=parseInt(saved,10);
      if(!isNaN(top))$nav.scrollTop(Math.max(0,top));
    }
    restoreSidebarScroll();
    window.requestAnimationFrame(restoreSidebarScroll);
    window.setTimeout(restoreSidebarScroll,60);
    window.setTimeout(restoreSidebarScroll,180);
    window.setTimeout(restoreSidebarScroll,400);
    $nav.on('scroll',function(){
      window.clearTimeout(saveTimer);
      saveTimer=window.setTimeout(saveSidebarScroll,40);
    });
    $(document).on('pointerdown mousedown touchstart click','.hrim-sidebar a[href]',saveSidebarScroll);
    window.addEventListener('beforeunload',saveSidebarScroll);
    window.addEventListener('pagehide',saveSidebarScroll);
  })();

  // v3.9.04: desktop show/hide sidebar toggle.
  (function(){
    var storageKey='roomscore_sidebar_collapsed';
    var $shell=$('.hrim-app-shell');
    var $toggle=$('#hrimSidebarCollapseToggle');
    if(!$shell.length||!$toggle.length)return;
    function applyCollapsed(collapsed){
      $shell.toggleClass('hrim-sidebar-collapsed',!!collapsed);
      $toggle.attr('aria-expanded',collapsed?'false':'true');
      $toggle.attr({'title':collapsed?'Show Menu':'Hide Menu','aria-label':collapsed?'Show Menu':'Hide Menu'});
      $toggle.find('.dashicons').toggleClass('dashicons-arrow-left-alt2',!collapsed).toggleClass('dashicons-arrow-right-alt2',!!collapsed);
    }
    var collapsed=false;
    try{collapsed=window.localStorage.getItem(storageKey)==='1';}catch(e){}
    if(window.matchMedia('(min-width: 761px)').matches)applyCollapsed(collapsed);
    $toggle.on('click',function(){
      if(!window.matchMedia('(min-width: 761px)').matches)return;
      var next=!$shell.hasClass('hrim-sidebar-collapsed');
      applyCollapsed(next);
      try{window.localStorage.setItem(storageKey,next?'1':'0');}catch(e){}
    });
    $(window).on('resize',function(){
      if(!window.matchMedia('(min-width: 761px)').matches){$shell.removeClass('hrim-sidebar-collapsed');}
      else {try{applyCollapsed(window.localStorage.getItem(storageKey)==='1');}catch(e){applyCollapsed(false);}}
    });
  })();

  // v3.8.24: apply date and inspector filters automatically.
  var hrimFilterTimer=null;
  $(document).on('change','.hrim-filter input[type=date], .hrim-filter select[name=inspector_id], .hrim-filter select[name=floor_id], .hrim-filter select[name=room_id], .hrim-filter select[name=section_id]',function(){
    var form=this.form;
    if(!form)return;
    if(this.name==='floor_id'){
      var roomSelect=form.querySelector('select[name=room_id]');
      if(roomSelect)roomSelect.value='';
    }
    clearTimeout(hrimFilterTimer);
    hrimFilterTimer=setTimeout(function(){
      if(form.requestSubmit){ form.requestSubmit(); } else { form.submit(); }
    },120);
  });
  $(document).on('click','.hrim-table-search',function(e){e.stopPropagation();});
  $(document).on('input','.hrim-table-search',function(){var panel=$(this).closest('.hrim-panel');panel.data('hrim-page',1);refreshTable(panel);});
  function hrimEsc(v){return $('<div>').text(v==null?'':String(v)).html();}
  function hrimEscapeHtml(v){return hrimEsc(v);}
  function hrimEscapeAttr(v){return hrimEsc(v).replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
  $('#hrimHotel').on('change',function(){
    var $r=$('#hrimRoom').html('<option>Search...</option>');
    $('#hrimQuestions').empty();
    $.post(HRIM.ajax,{action:'hrim_get_rooms',nonce:HRIM.nonce,hotel_id:this.value},function(res){
      $r.html('<option value="">Select Room</option>');
      if(res.success)res.data.forEach(function(x){$r.append($('<option>',{value:x.id,text:x.floor_name+' – '+x.room_no}));});
      var draftRoom=String($r.data('draft-room')||'');
      if(draftRoom){$r.val(draftRoom).trigger('change');$r.removeData('draft-room');}
    });
  });
  if($('#hrimHotel').length && $('#hrimHotel').val()){
    $('#hrimHotel').trigger('change');
  }

  $('#hrimRoom').on('change',function(){
    var roomId=this.value,$box=$('#hrimQuestions'),$footer=$('#hrimInspectionFooter');
    if(!roomId){$box.empty();$footer.hide();return;}
    $footer.show();
    $box.html('<div class="hrim-empty">Loading all assigned checklists...</div>');
    $.post(HRIM.ajax,{action:'hrim_get_room_checklist',nonce:HRIM.nonce,room_id:roomId},function(res){
      $box.empty();
      if(!res.success){$box.html('<div class="hrim-alert error">'+hrimEsc(res.data||'Checklist could not be loaded.')+'</div>');return;}
      if(!res.data.length){$box.html('<div class="hrim-empty">No template is assigned to this room or its room category.</div>');return;}
      res.data.forEach(function(t){
        var $group=$('<section class="hrim-template-group"></section>');
        $group.append('<input type="hidden" name="template_ids[]" value="'+t.id+'">');
        if(!t.questions.length){$group.append('<div class="hrim-empty">No checklist points found in this template.</div>');}
        var grouped={};
        t.questions.forEach(function(q){
          var sectionName=q.section_name||'General';
          if(!grouped[sectionName])grouped[sectionName]=[];
          grouped[sectionName].push(q);
        });
        Object.keys(grouped).forEach(function(sectionName){
          var questions=grouped[sectionName];
          var $section=$('<section class="hrim-section-group"></section>');
          $section.append('<div class="hrim-section-heading"><div class="hrim-section-heading-left"><span class="hrim-section-title">'+hrimEsc(sectionName)+'</span></div><div class="hrim-section-heading-right"><span class="hrim-section-progress"><span class="hrim-section-answered">0</span>/<span class="hrim-section-total">'+questions.length+'</span></span></div></div>');
          var $questions=$('<div class="hrim-section-questions"></div>');
          questions.forEach(function(q,index){
            var req=q.required_flag==1?' required':'',questionClass=q.question_type==='yes_no'?' hrim-question-yes-no':'',html='<div class="hrim-question-card'+questionClass+'"><div class="hrim-question-number">'+(index+1)+'</div><div class="hrim-question-content"><h3>'+hrimEsc(q.question)+(q.required_flag==1?' *':'')+'</h3>';
            if(q.question_type==='yes_no'){
              html+='<div class="hrim-checkpoint-actions"><div class="hrim-options"><label class="hrim-answer-option hrim-answer-yes"><input type="radio" name="answers['+t.id+']['+q.id+'][value]" value="yes"'+req+'><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><span class="hrim-option-text">Yes</span></label><label class="hrim-answer-option hrim-answer-no"><input type="radio" name="answers['+t.id+']['+q.id+'][value]" value="no"'+req+'><span class="dashicons dashicons-dismiss" aria-hidden="true"></span><span class="hrim-option-text">No</span></label></div><input type="hidden" name="answers['+t.id+']['+q.id+'][result]" value="pass">';
            }else if(q.question_type==='rating'){
              html+='<select name="answers['+t.id+']['+q.id+'][value]"'+req+'><option value="">Select rating</option><option>1</option><option>2</option><option>3</option><option>4</option><option>5</option></select><input type="hidden" name="answers['+t.id+']['+q.id+'][result]" value="pass">';
            }else{
              html+='<textarea name="answers['+t.id+']['+q.id+'][value]"'+req+'></textarea><input type="hidden" name="answers['+t.id+']['+q.id+'][result]" value="pass">';
            }
            html+='<div class="hrim-response-row"><div class="hrim-row-photo"><label class="hrim-photo-button" title="Add photo"><span class="dashicons dashicons-camera" aria-hidden="true"></span><span class="hrim-action-text">Photo</span><input type="file" accept="image/*" capture="environment" multiple hidden></label><input type="hidden" class="hrim-row-attachment-ids" name="answers['+t.id+']['+q.id+'][attachment_ids]" value=""><div class="hrim-row-photo-preview"></div></div><div class="hrim-row-video"><label class="hrim-video-button" title="Add video up to 1 minute"><span class="dashicons dashicons-video-alt3" aria-hidden="true"></span><span class="hrim-action-text">Video</span><input type="file" accept="video/mp4,video/quicktime,video/webm" capture="environment" hidden></label><input type="hidden" class="hrim-row-video-ids" name="answers['+t.id+']['+q.id+'][video_ids]" value=""><div class="hrim-row-video-preview"></div></div><button type="button" class="hrim-remarks-toggle" title="Remarks" aria-expanded="false"><span class="dashicons dashicons-admin-comments" aria-hidden="true"></span><span class="hrim-action-text">Remarks</span></button></div>'+(q.question_type==='yes_no'?'</div>':'')+'<textarea class="hrim-checkpoint-remarks" name="answers['+t.id+']['+q.id+'][remarks]" placeholder="Remarks (optional)"></textarea></div></div>';
            $questions.append(html);
          });
          $section.append($questions);
          $section.append('<div class="hrim-section-save-wrap"><button type="button" class="hrim-primary hrim-save-section" data-section-name="'+hrimEscapeAttr(sectionName)+'">Save '+hrimEsc(sectionName)+'</button><span class="hrim-section-save-status" aria-live="polite"></span></div>');
          $group.append($section);
        });
        $box.append($group);
      });
      hrimRestoreDraftAnswers();
      hrimUpdateInspectionCompletion();
    });
  });


  $(document).on('click','.hrim-save-section',function(){
    var $button=$(this),$form=$('#hrimInspectionForm');
    if(!$form.length)return;
    var roomId=String($('#hrimRoom').val()||'');
    if(!roomId){alert('Please select a room first.');return;}
    var batch=$('#hrimDraftBatchId').val();
    if(!batch){
      batch=(window.crypto&&window.crypto.randomUUID)?window.crypto.randomUUID():('draft-'+Date.now()+'-'+Math.random().toString(16).slice(2));
      $('#hrimDraftBatchId').val(batch);
    }
    var formData=new FormData($form[0]);
    formData.set('save_mode','in_progress');
    $button.prop('disabled',true);
    var original=$button.text();
    $button.text('Saving...');
    hrimShowSpinner('Saving section...');
    fetch($form.attr('action'),{method:'POST',body:formData,credentials:'same-origin'})
      .then(function(response){if(!response.ok)throw new Error('Save failed');return response.text();})
      .then(function(){
        $button.closest('.hrim-section-save-wrap').find('.hrim-section-save-status').text('Section saved successfully.');
        $button.text(original).prop('disabled',false);
        hrimHideSpinner();
      })
      .catch(function(){
        $button.text(original).prop('disabled',false);
        hrimHideSpinner();
        alert('Section could not be saved. Please try again.');
      });
  });

  function hrimRestoreDraftAnswers(){
    var draft=window.HRIM_DRAFT_ANSWERS||{};
    Object.keys(draft).forEach(function(qid){
      var data=draft[qid]||{};
      var $value=$('[name$="['+qid+'][value]"]');
      if($value.is(':radio')){$value.filter('[value="'+String(data.value||'')+'"]').prop('checked',true).trigger('change');}
      else {$value.val(data.value||'');}
      $('[name$="['+qid+'][result]"]').val(data.result||'pass');
      $('[name$="['+qid+'][remarks]"]').val(data.remarks||'');
      var ids=Array.isArray(data.attachments)?data.attachments:[];
      $('[name$="['+qid+'][attachment_ids]"]').val(ids.join(','));
      if(ids.length){
        var $preview=$('[name$="['+qid+'][attachment_ids]"]').siblings('.hrim-row-photo-preview');
        ids.forEach(function(id){$preview.append('<span class="hrim-photo-chip" data-id="'+id+'"><span class="dashicons dashicons-format-image"></span><button type="button" aria-label="Remove photo">×</button></span>');});
      }
      var videoIds=Array.isArray(data.videos)?data.videos:[];
      $('[name$="['+qid+'][video_ids]"]').val(videoIds.join(','));
      if(videoIds.length){
        var $videoPreview=$('[name$="['+qid+'][video_ids]"]').siblings('.hrim-row-video-preview');
        videoIds.forEach(function(id){$videoPreview.append('<span class="hrim-video-chip" data-id="'+id+'"><span class="dashicons dashicons-video-alt3"></span><button type="button" aria-label="Remove video">×</button></span>');});
      }
    });
  }

  function hrimUpdateInspectionCompletion(){
    var $form=$('#hrimInspectionForm'); if(!$form.length)return;
    var complete=true,hasQuestions=false;
    $form.find('.hrim-question-card').each(function(){
      hasQuestions=true;
      var $card=$(this),$required=$card.find('input[required],select[required],textarea[required]').filter(function(){return !$(this).is('[name$="[remarks]"]');});
      if($required.length){
        if($required.is(':radio')){if(!$required.filter(':checked').length)complete=false;}
        else $required.each(function(){if(!String($(this).val()||'').trim())complete=false;});
      }
      var no=$card.find('input[type=radio][value=no]:checked').length>0;
      if(no&&!String($card.find('textarea[name$="[remarks]"]').val()||'').trim())complete=false;
    });
    $form.find('.hrim-section-group').each(function(){
      var $section=$(this),$cards=$section.find('.hrim-question-card'),answered=0;
      $cards.each(function(){
        var $card=$(this),isAnswered=false;
        if($card.find('input[type=radio]:checked').length){isAnswered=true;}
        else if($card.find('select').filter(function(){return String($(this).val()||'').trim()!=='';}).length){isAnswered=true;}
        else if($card.find('textarea').not('[name$="[remarks]"]').filter(function(){return String($(this).val()||'').trim()!=='';}).length){isAnswered=true;}
        if(isAnswered)answered++;
      });
      $section.find('.hrim-section-answered').first().text(answered);
      $section.find('.hrim-section-total').first().text($cards.length);
    });
    $form.find('.hrim-submit-complete').prop('hidden',!(hasQuestions&&complete));
  }

  $(document).on('change input','#hrimInspectionForm .hrim-question-card input, #hrimInspectionForm .hrim-question-card select, #hrimInspectionForm .hrim-question-card textarea',hrimUpdateInspectionCompletion);

  $(document).on('change','.hrim-row-photo input[type=file]',function(){
    var input=this,$wrap=$(this).closest('.hrim-row-photo'),$hidden=$wrap.find('.hrim-row-attachment-ids'),$preview=$wrap.find('.hrim-row-photo-preview');
    Array.from(input.files||[]).forEach(function(file){
      var fd=new FormData(); fd.append('action','hrim_upload_image'); fd.append('nonce',HRIM.nonce); fd.append('file',file);
      $.ajax({url:HRIM.ajax,method:'POST',data:fd,processData:false,contentType:false}).done(function(res){
        if(!res.success){alert(res.data||'Photo upload failed.');return;}
        var ids=String($hidden.val()||'').split(',').filter(Boolean); ids.push(String(res.data.id)); $hidden.val(ids.join(','));
        $preview.append('<span class="hrim-photo-chip" data-id="'+res.data.id+'"><img src="'+hrimEscapeAttr(res.data.url)+'" alt=""><button type="button" aria-label="Remove photo">×</button></span>');
      });
    });
    input.value='';
  });
  $(document).on('click','.hrim-photo-chip button',function(){
    var $chip=$(this).closest('.hrim-photo-chip'),id=String($chip.data('id')),$wrap=$chip.closest('.hrim-row-photo'),$hidden=$wrap.find('.hrim-row-attachment-ids');
    var ids=String($hidden.val()||'').split(',').filter(function(x){return x&&x!==id;});$hidden.val(ids.join(','));$chip.remove();
  });

  function hrimVideoDuration(file){
    return new Promise(function(resolve,reject){
      var video=document.createElement('video');
      video.preload='metadata';
      video.onloadedmetadata=function(){URL.revokeObjectURL(video.src);resolve(Number(video.duration||0));};
      video.onerror=function(){URL.revokeObjectURL(video.src);reject(new Error('Unable to read video duration.'));};
      video.src=URL.createObjectURL(file);
    });
  }
  $(document).on('change','.hrim-row-video input[type=file]',function(){
    var input=this,$wrap=$(this).closest('.hrim-row-video'),$hidden=$wrap.find('.hrim-row-video-ids'),$preview=$wrap.find('.hrim-row-video-preview');
    var file=(input.files||[])[0]; if(!file)return;
    if(file.size>40*1024*1024){alert('Video size cannot exceed 40 MB.');input.value='';return;}
    hrimVideoDuration(file).then(function(duration){
      if(!duration||duration>60.5){alert('Video duration cannot exceed 1 minute.');input.value='';return;}
      var fd=new FormData();fd.append('action','hrim_upload_video');fd.append('nonce',HRIM.nonce);fd.append('duration',duration);fd.append('file',file);
      hrimShowSpinner('Uploading video...');
      $.ajax({url:HRIM.ajax,method:'POST',data:fd,processData:false,contentType:false}).done(function(res){
        if(!res.success){alert(res.data||'Video upload failed.');return;}
        var ids=String($hidden.val()||'').split(',').filter(Boolean);ids.push(String(res.data.id));$hidden.val(ids.join(','));
        $preview.html('<span class="hrim-video-chip" data-id="'+res.data.id+'"><span class="dashicons dashicons-video-alt3"></span><small>'+Math.round(duration)+' sec</small><button type="button" aria-label="Remove video">×</button></span>');
      }).always(function(){hrimHideSpinner();input.value='';});
    }).catch(function(err){alert(err.message||'Unable to read video.');input.value='';});
  });
  $(document).on('click','.hrim-video-chip button',function(){
    var $chip=$(this).closest('.hrim-video-chip'),id=String($chip.data('id')),$wrap=$chip.closest('.hrim-row-video'),$hidden=$wrap.find('.hrim-row-video-ids');
    var ids=String($hidden.val()||'').split(',').filter(function(x){return x&&x!==id;});$hidden.val(ids.join(','));$chip.remove();
  });



  function hrimStyleSelectedResponse($radio){
    var $options=$radio.closest('.hrim-options');
    var $card=$radio.closest('.hrim-question-card');
    $options.find('label').removeClass('hrim-option-selected-yes hrim-option-selected-no hrim-option-selected-na');
    $card.removeClass('hrim-response-yes hrim-response-no hrim-response-na');
    if(!$radio.prop('checked'))return;
    var value=String($radio.val()||'').toLowerCase();
    if(value==='yes'){
      $radio.closest('label').addClass('hrim-option-selected-yes');
      $card.addClass('hrim-response-yes');
    }else if(value==='no'){
      $radio.closest('label').addClass('hrim-option-selected-no');
      $card.addClass('hrim-response-no');
    }else if(value==='na'){
      $radio.closest('label').addClass('hrim-option-selected-na');
      $card.addClass('hrim-response-na');
    }
  }

  $(document).on('change','.hrim-question-card input[type=radio]',function(){
    var $c=$(this).closest('.hrim-question-card');
    var isNo=this.value==='no';
    var $remarks=$c.find('textarea[name$="[remarks]"]');
    hrimStyleSelectedResponse($(this));
    $c.find('input[name$="[result]"]').val(isNo?'fail':'pass');
    $remarks.prop('required',isNo).attr('aria-required',isNo?'true':'false');
    $remarks.attr('placeholder',isNo?'Remarks are required':'Remarks (optional)');
    if(isNo){
      $c.addClass('hrim-remarks-open');
      $c.find('.hrim-remarks-toggle').attr('aria-expanded','true');
    }else{
      $remarks.removeClass('hrim-remarks-invalid');
      if(!String($remarks.val()||'').trim()){
        $c.removeClass('hrim-remarks-open');
        $c.find('.hrim-remarks-toggle').attr('aria-expanded','false');
      }
    }
    $c.toggleClass('hrim-failed-answer',isNo);
  });

  $(document).on('click','.hrim-remarks-toggle',function(){
    var $card=$(this).closest('.hrim-question-card');
    var $remarks=$card.find('textarea[name$="[remarks]"]');
    var noSelected=$card.find('input[type=radio][value=no]:checked').length>0;
    var isOpen=$card.hasClass('hrim-remarks-open');
    if(isOpen && noSelected && !String($remarks.val()||'').trim()){
      $remarks.focus();
      return;
    }
    $card.toggleClass('hrim-remarks-open',!isOpen);
    $(this).attr('aria-expanded',!isOpen?'true':'false');
    if(!isOpen)$remarks.focus();
  });

  $('.hrim-question-card input[type=radio]:checked').each(function(){
    hrimStyleSelectedResponse($(this));
  });
  $(document).on('submit','#hrimInspectionForm',function(e){
    var mode=String($(document.activeElement).val()||'complete');
    if(mode==='in_progress'){ $(this).find('[required]').prop('required',false); return true; }
    var firstMissing=null;
    $(this).find('.hrim-question-card').each(function(){
      var $card=$(this),noSelected=$card.find('input[type=radio][value=no]:checked').length>0;
      var $remarks=$card.find('textarea[name$="[remarks]"]');
      if(noSelected && !String($remarks.val()||'').trim()){ firstMissing=firstMissing||$remarks; $card.addClass('hrim-failed-answer'); $remarks.addClass('hrim-remarks-invalid'); } else { $remarks.removeClass('hrim-remarks-invalid'); }
    });
    if(firstMissing){
      e.preventDefault();
      alert('Remarks are required for every checkpoint marked No.');
      firstMissing.focus();
      $('html,body').animate({scrollTop:firstMissing.closest('.hrim-question-card').offset().top-120},250);
      return false;
    }
  });

  $(document).on('input','.hrim-question-card textarea[name$="[remarks]"]',function(){
    if(String($(this).val()||'').trim()){$(this).removeClass('hrim-remarks-invalid');}
  });
  $(document).on('click','.hrim-assign-issue',function(){
    $('#hrimAssignIssueId').val($(this).data('id'));
    $('#hrimAssignIssueUser').val($(this).data('assigned')||'');
    $('#hrimAssignIssueDue').val($(this).data('due')||'');
    $('#hrimAssignIssueRemarks').val('');
    $('#hrimAssignIssueModal').addClass('open');
  });
  $(document).on('click','.hrim-edit-issue',function(){
    $('#hrimEditIssueId').val($(this).data('id'));
    $('#hrimEditIssueTitle').val($(this).data('title')||'');
    $('#hrimEditIssueDescription').val($(this).data('description')||'');
    $('#hrimEditIssuePriority').val($(this).data('priority')||'medium');
    $('#hrimEditIssueDue').val($(this).data('due')||'');
    $('#hrimEditIssueModal').addClass('open');
  });
  $(document).on('click','.hrim-progress-issue',function(){
    if(!window.hrimRestoringIssueForm){
      $('#hrimProgressIssueModal .hrim-form-alert').remove();
    }
    $('#hrimProgressIssueId').val($(this).data('id'));
    $('#hrimProgressIssueTitle').text($(this).data('title')||'—');
    $('#hrimProgressIssueDescription').text($(this).data('issue')||'—');
    $('#hrimProgressIssueLocation').text($(this).data('location')||'');
    var current=$(this).data('status');
    $('#hrimProgressIssueStatus').val(current==='resolved'?'resolved':'in_progress');
    $('#hrimProgressIssueRemarks').val($(this).data('resolution')||'');
    $('#hrimProgressIssueModal').addClass('open');
  });
  $(document).on('click','.hrim-view-inspection',function(){var id=$(this).data('target');if(id)$('#'+id).addClass('open');});
  $(document).on('click keydown','.hrim-in-progress-row',function(e){
    if(e.type==='keydown' && e.key!=='Enter' && e.key!==' ') return;
    if($(e.target).closest('a,button,form,input,select,textarea,label').length) return;
    if(e.type==='keydown') e.preventDefault();
    var target=$(this).attr('data-target');
    if(!target) return;
    var $detail=$('#'+target);
    var opening=$detail.prop('hidden');
    $('.hrim-in-progress-detail-row').prop('hidden',true);
    $('.hrim-in-progress-row').removeClass('is-expanded').attr('aria-expanded','false');
    if(opening){
      $detail.prop('hidden',false);
      $(this).addClass('is-expanded').attr('aria-expanded','true');
    }
  });
  $(document).on('click','.hrim-section-score-row',function(){
    var target=$(this).attr('data-target');
    if(!target)return;
    var $panel=$('#'+target);
    var opening=!$panel.hasClass('is-open');
    $(this).closest('.hrim-inspection-detail').find('.hrim-section-score-row').removeClass('is-active').attr('aria-expanded','false');
    $(this).closest('.hrim-inspection-detail').find('.hrim-section-detail-panel').removeClass('is-open').attr('hidden',true);
    if(opening){$(this).addClass('is-active').attr('aria-expanded','true');$panel.addClass('is-open').removeAttr('hidden');}
  });
  $(document).on('click','.hrim-print-inspection',function(){document.body.classList.add('hrim-printing-inspection');window.print();setTimeout(function(){document.body.classList.remove('hrim-printing-inspection');},300);});

  $(document).on('click','.hrim-direct-inspection-pdf',function(){
    var target=$(this).data('target');
    var id=$(this).data('inspection-id')||'';
    if(!target)return;
    var $modal=$('#'+target);
    $modal.addClass('open');
    var oldTitle=document.title;
    document.title='Inspection-'+id;
    document.body.classList.add('hrim-printing-inspection');
    setTimeout(function(){
      window.print();
      setTimeout(function(){
        document.body.classList.remove('hrim-printing-inspection');
        document.title=oldTitle;
        $modal.removeClass('open');
      },500);
    },100);
  });
  $(document).on('click','.hrim-download-inspection-pdf',function(){var oldTitle=document.title;var id=$(this).data('inspection-id')||'';document.title='Inspection-'+id;document.body.classList.add('hrim-printing-inspection');window.print();setTimeout(function(){document.body.classList.remove('hrim-printing-inspection');document.title=oldTitle;},500);});
  function hrimRenderDocumentGallery(docs){
    var $body=$('#hrimDocumentModalBody').empty();
    if(!docs.length){
      $body.html('<div class="hrim-empty">No photos found.</div>');
      return;
    }

    var $gallery=$('<div class="hrim-document-gallery"></div>');
    var $stage=$('<div class="hrim-document-stage"></div>');
    var $meta=$('<div class="hrim-document-active-meta"></div>');
    var $thumbs=$('<div class="hrim-document-thumbs" role="list"></div>');
    $gallery.append($stage,$meta,$thumbs);
    $body.append($gallery);

    function showDocument(index){
      var doc=docs[index]||{};
      var mime=String(doc.mime||'');
      var isImage=mime.indexOf('image/')===0;
      var url=hrimEscapeAttr(doc.url||'');
      var ctx=doc.context||{};
      var content=isImage
        ? '<a href="'+url+'" target="_blank" rel="noopener" aria-label="Open image"><img src="'+url+'" alt="Photo preview"></a>'
        : '<a class="hrim-document-stage-file" href="'+url+'" target="_blank" rel="noopener" aria-label="Open document"><span aria-hidden="true">📄</span><small>Open document</small></a>';
      $stage.html(content);

      var meta='';
      if(ctx.date)meta+='<div><strong>Update Date:</strong> '+hrimEscapeHtml(ctx.date)+'</div>';
      if(ctx.status)meta+='<div><strong>Status:</strong> '+hrimEscapeHtml(ctx.status)+'</div>';
      if(ctx.remarks)meta+='<div><strong>Remarks:</strong> '+hrimEscapeHtml(ctx.remarks)+'</div>';
      if(ctx.updated_by)meta+='<div><strong>Updated By:</strong> '+hrimEscapeHtml(ctx.updated_by)+'</div>';
      $meta.html(meta).toggle(!!meta);

      $thumbs.find('.hrim-document-thumb').removeClass('is-active').attr('aria-selected','false');
      $thumbs.find('.hrim-document-thumb').eq(index).addClass('is-active').attr('aria-selected','true');
    }

    docs.forEach(function(doc,index){
      var mime=String(doc.mime||'');
      var isImage=mime.indexOf('image/')===0;
      var thumb=isImage
        ? '<img src="'+hrimEscapeAttr(doc.url||'')+'" alt="Photo thumbnail">'
        : '<span class="hrim-document-thumb-file" aria-hidden="true">📄</span>';
      var $thumb=$('<button type="button" class="hrim-document-thumb" role="option" aria-selected="false" aria-label="Show document '+(index+1)+'">'+thumb+'</button>');
      $thumb.on('click',function(){showDocument(index);});
      $thumbs.append($thumb);
    });

    showDocument(0);
  }

  $(document).on('click','.hrim-document-open',function(){
    var docs=[];
    try{docs=JSON.parse($(this).attr('data-documents')||'[]');}catch(e){docs=[];}
    hrimRenderDocumentGallery(docs);
    $('#hrimDocumentModal').addClass('open');
  });
  $(document).on('click','.hrim-modal-close',function(){$('.hrim-modal').removeClass('open');});
  $(document).on('click','.hrim-export-csv',function(){
    var $btn=$(this),target=String($btn.attr('data-table')||''),$table=target?$('#'+target):$();
    if(!$table.length){var $scope=$btn.closest('.hrim-panel');$table=$scope.find('table.hrim-exportable').first();}
    if(!$table.length){$table=$btn.closest('.hrim-content').find('table.hrim-exportable').first();}
    if(!$table.length)return;
    var $panel=$table.closest('.hrim-panel'),q=String($panel.find('.hrim-table-search').val()||'').toLowerCase(),rows=[];
    $table.find('tr').each(function(){
      if($(this).find('.hrim-empty').length)return;
      if($(this).closest('tbody').length && q && $(this).text().toLowerCase().indexOf(q)===-1)return;
      var cols=[];
      $(this).find('th,td').not('.hrim-no-export').each(function(){var v=$(this).attr('data-export-value');if(v===undefined)v=$(this).text().trim();cols.push('\"'+String(v).replace(/\"/g,'\"\"')+'\"');});
      if(cols.length)rows.push(cols.join(','));
    });
    var blob=new Blob(['\ufeff'+rows.join('\r\n')],{type:'text/csv;charset=utf-8;'}),a=document.createElement('a'),name=String($btn.data('filename')||'hotel-inspection-export').replace(/[^a-z0-9_-]+/gi,'-');
    a.href=URL.createObjectURL(blob);a.download=name+'.csv';document.body.appendChild(a);a.click();document.body.removeChild(a);URL.revokeObjectURL(a.href);
  });

  function initTables(){
    $('.hrim-panel').each(function(){
      var $panel=$(this),$table=$panel.find('table.hrim-table').first();
      if(!$table.length||$table.data('hrim-ready'))return;
      var isCommunicationsTable=$table.closest('.hrim-communications-grid').length>0;
      $table.data('hrim-ready',1);$panel.data('hrim-page',1).data('hrim-size',20);
      if(!isCommunicationsTable && typeof window.hrimEnsureUniversalControls==='function') window.hrimEnsureUniversalControls($panel,$table);
      // Every main application table can be exported to an Excel-compatible CSV.
      // Inspection detail/modal tables are intentionally excluded.
      if(!isCommunicationsTable && !$table.closest('.hrim-modal,.hrim-inspection-detail').length){
        var tableId=$table.attr('id');
        if(!tableId){tableId='hrimExportTable'+($('.hrim-table').index($table)+1);$table.attr('id',tableId);}
        $table.addClass('hrim-exportable');
        var $existing=$panel.find('.hrim-export-csv').first(),$content=$table.closest('.hrim-content'),$contentTables=$content.find('table.hrim-table').filter(function(){return !$(this).closest('.hrim-modal,.hrim-inspection-detail').length;}),$contentButtons=$content.find('.hrim-export-csv');
        if(!$existing.length && $contentTables.length===1 && $contentButtons.length){$existing=$contentButtons.first();}
        if(!$existing.length){
          var title=String($panel.find('.hrim-panel-head h2').first().text()||document.title||'hotel-inspection-export').trim();
          var fileName=title.toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'')||'hotel-inspection-export';
          var $button=$('<button type="button" class="hrim-secondary hrim-export-csv hrim-icon-only-button" title="Download Excel" aria-label="Download Excel"><span class="dashicons dashicons-download" aria-hidden="true"></span></button>').attr('data-filename',fileName).attr('data-table',tableId);
          var $toolbar=$panel.find('.hrim-panel-head .hrim-toolbar').first();
          if($toolbar.length){$toolbar.prepend($button);}else if($panel.find('.hrim-panel-head').length){$panel.find('.hrim-panel-head').first().append($button);}else{$panel.prepend($('<div class="hrim-toolbar hrim-auto-export-toolbar"></div>').append($button));}
        }else{$existing.attr('data-table',tableId);}
      }
      var $heads=$table.children('thead').find('th');
      $heads.each(function(i){if(i<$heads.length-1&&!$(this).text().toLowerCase().includes('action'))$(this).addClass('hrim-sortable').attr('data-col',i);});
      $table.after('<div class="hrim-table-meta"><div class="hrim-row-count"></div><div class="hrim-pagination"></div></div>');
      refreshTable($panel);
    });
  }
  function refreshTable($panel){
    var $table=$panel.find('table.hrim-table').first();if(!$table.length)return;
    var q=String($panel.find('.hrim-table-search').val()||'').toLowerCase();
    if($table.hasClass('hrim-sectionwise-table')){
      var $rows=$table.children('tbody').children('tr'),items=[];
      $rows.hide();
      $rows.filter('.hrim-section-heading-row').each(function(){
        var $heading=$(this),$children=$heading.nextUntil('.hrim-section-heading-row','.hrim-section-checkpoint-row');
        var sectionText=String($heading.text()||'').toLowerCase();
        var sectionMatch=!q||sectionText.indexOf(q)>-1;
        var $matched=$children.filter(function(){return sectionMatch||String($(this).text()||'').toLowerCase().indexOf(q)>-1;});
        if(sectionMatch){$matched=$children;}
        $matched.each(function(){items.push({heading:$heading[0],row:this});});
      });
      var sectionSize=parseInt($panel.data('hrim-size')||20,10),sectionPages=Math.max(1,Math.ceil(items.length/sectionSize)),sectionPage=Math.min(parseInt($panel.data('hrim-page')||1,10),sectionPages);
      $panel.data('hrim-page',sectionPage);
      var visibleHeadings=[];
      items.slice((sectionPage-1)*sectionSize,sectionPage*sectionSize).forEach(function(item){
        if(visibleHeadings.indexOf(item.heading)===-1){$(item.heading).show();visibleHeadings.push(item.heading);}
        $(item.row).show();
      });
      $panel.find('.hrim-row-count').html('<strong>'+items.length+'</strong> record'+(items.length===1?'':'s'));
      var $sectionPager=$panel.find('.hrim-pagination').empty();
      if(items.length>sectionSize){
        $sectionPager.append('<button data-page="'+(sectionPage-1)+'" '+(sectionPage<=1?'disabled':'')+'>Previous</button>');
        for(var sp=1;sp<=sectionPages;sp++){if(sectionPages>7&&Math.abs(sp-sectionPage)>2&&sp!==1&&sp!==sectionPages)continue;$sectionPager.append('<button class="'+(sp===sectionPage?'current':'')+'" data-page="'+sp+'">'+sp+'</button>');}
        $sectionPager.append('<button data-page="'+(sectionPage+1)+'" '+(sectionPage>=sectionPages?'disabled':'')+'>Next</button>');
      }
      return;
    }
    var $tbody=$table.children('tbody');
    var rows=$tbody.children('tr').filter(function(){return !$(this).find('.hrim-empty').length;}).get();
    var sortCol=$panel.data('hrim-sort-col'),sortDir=$panel.data('hrim-sort-dir')||'asc';
    if(sortCol!==undefined){rows.sort(function(a,b){var av=$(a).children().eq(sortCol).text().trim(),bv=$(b).children().eq(sortCol).text().trim();var an=parseFloat(av),bn=parseFloat(bv);var cmp=(!isNaN(an)&&!isNaN(bn))?an-bn:av.localeCompare(bv,undefined,{numeric:true,sensitivity:'base'});return sortDir==='asc'?cmp:-cmp;});$.each(rows,function(_,r){$tbody.append(r);});}
    var matched=rows.filter(function(r){
      var searchMatch=$(r).text().toLowerCase().indexOf(q)>-1;
      var filterMatch=(typeof window.hrimUniversalRowPassesFilters!=='function')||window.hrimUniversalRowPassesFilters($panel,r);
      return searchMatch&&filterMatch;
    });
    var noPagination=$panel.hasClass('hrim-no-pagination');
    var size=noPagination?Math.max(matched.length,1):parseInt($panel.data('hrim-size')||20,10),pages=Math.max(1,Math.ceil(matched.length/size)),page=noPagination?1:Math.min(parseInt($panel.data('hrim-page')||1,10),pages);$panel.data('hrim-page',page);
    $(rows).hide();matched.slice((page-1)*size,page*size).forEach(function(r){$(r).show();});
    $panel.find('.hrim-row-count').html('<strong>'+matched.length+'</strong> record'+(matched.length===1?'':'s'));
    var $pager=$panel.find('.hrim-pagination').empty();if(!noPagination&&matched.length>size){$pager.append('<button data-page="'+(page-1)+'" '+(page<=1?'disabled':'')+'>Previous</button>');for(var i=1;i<=pages;i++){if(pages>7&&Math.abs(i-page)>2&&i!==1&&i!==pages)continue;$pager.append('<button class="'+(i===page?'current':'')+'" data-page="'+i+'">'+i+'</button>');}$pager.append('<button data-page="'+(page+1)+'" '+(page>=pages?'disabled':'')+'>Next</button>');}
  }
  window.hrimRefreshTable=refreshTable;
  $(document).on('click','.hrim-pagination button:not(:disabled)',function(){var $p=$(this).closest('.hrim-panel');$p.data('hrim-page',parseInt($(this).data('page'),10));refreshTable($p);});
  $(document).on('click','.hrim-sortable',function(){var $p=$(this).closest('.hrim-panel'),col=parseInt($(this).data('col'),10),old=$p.data('hrim-sort-col'),dir=(old===col&&$p.data('hrim-sort-dir')==='asc')?'desc':'asc';$p.data('hrim-sort-col',col).data('hrim-sort-dir',dir).data('hrim-page',1);$(this).siblings().removeClass('asc desc');$(this).removeClass('asc desc').addClass(dir);refreshTable($p);});
  initTables();
  // Link any remaining filter-level Excel buttons to the corresponding main table.
  $('.hrim-content').each(function(){
    var $content=$(this),$tables=$content.find('table.hrim-exportable').filter(function(){return !$(this).closest('.hrim-modal,.hrim-inspection-detail').length;});
    $content.find('.hrim-export-csv').each(function(i){if(!$(this).attr('data-table') && $tables.eq(i).length)$(this).attr('data-table',$tables.eq(i).attr('id'));});
  });
})(jQuery);



/* v3.15.08: universal search and context-aware filters for every main table view. */
(function($){
  'use strict';

  function cleanText(value){ return String(value == null ? '' : value).replace(/\s+/g,' ').trim(); }
  function normaliseHeader(value){ return cleanText(value).toLowerCase().replace(/[^a-z0-9]+/g,' '); }
  function parseTableDate(value){
    var text=cleanText(value);
    var match=text.match(/^(\d{1,2})[\/-](\d{1,2})[\/-](\d{2,4})/);
    if(!match) return null;
    var year=parseInt(match[3],10); if(year<100) year+=2000;
    var date=new Date(year,parseInt(match[2],10)-1,parseInt(match[1],10));
    return isNaN(date.getTime())?null:date;
  }
  function isoDate(value){
    if(!value) return null;
    var parts=String(value).split('-');
    if(parts.length!==3) return null;
    var date=new Date(parseInt(parts[0],10),parseInt(parts[1],10)-1,parseInt(parts[2],10));
    return isNaN(date.getTime())?null:date;
  }
  function mainTable($table){
    return $table.length && !$table.closest('.hrim-modal,.hrim-inspection-detail,.hrim-section-detail-panel,.hrim-dashboard-panel').length;
  }
  function tableHeaders($table){
    var headers=[];
    $table.children('thead').find('th').each(function(index){ headers.push({index:index,label:cleanText($(this).text()),key:normaliseHeader($(this).text())}); });
    return headers;
  }
  function matchingHeader(headers,names){
    for(var i=0;i<headers.length;i++){
      for(var j=0;j<names.length;j++){
        if(headers[i].key===names[j] || headers[i].key.indexOf(names[j])!==-1) return headers[i];
      }
    }
    return null;
  }
  function uniqueValues($table,index){
    var found={};
    $table.children('tbody').children('tr').each(function(){
      if($(this).find('.hrim-empty').length) return;
      var value=cleanText($(this).children('td').eq(index).text());
      if(value && value!=='—' && value!=='-') found[value]=true;
    });
    return Object.keys(found).sort(function(a,b){return a.localeCompare(b,undefined,{numeric:true,sensitivity:'base'});});
  }
  function addSelect($filters,$table,header,label){
    if(!header) return;
    var values=uniqueValues($table,header.index); if(!values.length) return;
    var $label=$('<label class="hrim-universal-filter-field"><span>'+label+'</span></label>');
    var $select=$('<select class="hrim-universal-column-filter"><option value="">All</option></select>').attr('data-col',header.index);
    values.forEach(function(value){$select.append($('<option></option>').val(value).text(value));});
    $label.append($select); $filters.append($label);
  }
  function ensureUniversalControls($panel,$table){
    if(!mainTable($table) || $panel.data('hrim-universal-controls')) return;
    $panel.data('hrim-universal-controls',1);

    var $head=$panel.children('.hrim-panel-head').first();
    if(!$head.length){ $head=$('<div class="hrim-panel-head hrim-master-panel-head"></div>'); $panel.prepend($head); }
    var $toolbar=$head.find('.hrim-toolbar').first();
    if(!$toolbar.length){ $toolbar=$('<div class="hrim-toolbar hrim-master-toolbar"></div>'); $head.append($toolbar); }
    if(!$panel.find('input.hrim-table-search').first().length){ $toolbar.prepend('<input type="search" class="hrim-table-search" placeholder="" aria-label="Search table">'); }

    var headers=tableHeaders($table), dateHeader=matchingHeader(headers,['date','due date','last inspection date','last updated','created date','update date']);
    var $filters=$('<div class="hrim-universal-table-filters hrim-no-print"></div>');
    if(dateHeader){
      $filters.append('<label class="hrim-universal-filter-field"><span>From Date</span><input type="date" class="hrim-universal-date-from" data-col="'+dateHeader.index+'"></label>');
      $filters.append('<label class="hrim-universal-filter-field"><span>To Date</span><input type="date" class="hrim-universal-date-to" data-col="'+dateHeader.index+'"></label>');
    }
    addSelect($filters,$table,matchingHeader(headers,['property','hotel']),'Property');
    addSelect($filters,$table,matchingHeader(headers,['floor']),'Floor');
    addSelect($filters,$table,matchingHeader(headers,['room']),'Room');
    addSelect($filters,$table,matchingHeader(headers,['department']),'Department');
    addSelect($filters,$table,matchingHeader(headers,['inspector']),'Inspector');
    addSelect($filters,$table,matchingHeader(headers,['status']),'Status');
    addSelect($filters,$table,matchingHeader(headers,['applicability']),'Applicability');
    addSelect($filters,$table,matchingHeader(headers,['section']),'Section');
    if($filters.children().length){ $head.after($filters); }
  }
  function rowPassesFilters($panel,row){
    var $row=$(row), pass=true;
    $panel.find('.hrim-universal-column-filter').each(function(){
      var selected=cleanText($(this).val()); if(!selected) return;
      var col=parseInt($(this).attr('data-col'),10), value=cleanText($row.children('td').eq(col).text());
      if(value!==selected) pass=false;
    });
    if(!pass) return false;
    var $from=$panel.find('.hrim-universal-date-from').first(),$to=$panel.find('.hrim-universal-date-to').first();
    if($from.length || $to.length){
      var col=parseInt(($from.length?$from:$to).attr('data-col'),10), rowDate=parseTableDate($row.children('td').eq(col).text());
      var fromDate=isoDate($from.val()),toDate=isoDate($to.val());
      if((fromDate||toDate) && !rowDate) return false;
      if(fromDate && rowDate<fromDate) return false;
      if(toDate){ var end=new Date(toDate.getFullYear(),toDate.getMonth(),toDate.getDate(),23,59,59,999); if(rowDate>end) return false; }
    }
    return true;
  }
  window.hrimUniversalRowPassesFilters=rowPassesFilters;
  window.hrimEnsureUniversalControls=ensureUniversalControls;

  $(document).on('change','.hrim-universal-table-filters input,.hrim-universal-table-filters select',function(){
    var $panel=$(this).closest('.hrim-panel'); $panel.data('hrim-page',1);
    if(typeof window.hrimRefreshTable==='function') window.hrimRefreshTable($panel);
  });
})(jQuery);
(function($){
  function roomQuestionRow(){
    var sections='<option value="">Select section</option>';
    var raw=$('#hrim-room-question-rows').attr('data-sections')||'[]',items=[];
    try{items=JSON.parse(raw);}catch(e){items=[];}
    $.each(items,function(_,sec){sections+='<option value="'+hrimEscapeAttr(sec.id)+'">'+hrimEscapeHtml(sec.name)+'</option>';});
    return '<div class="hrim-room-question-row"><input type="hidden" name="additional_id[]" value="0"><label>Section<select name="additional_section_id[]" required>'+sections+'</select></label><label class="hrim-grow">Checkpoint<input name="additional_question[]" placeholder="Enter asset-specific checkpoint"></label><button type="button" class="hrim-icon-btn hrim-delete hrim-remove-room-question" title="Remove"><span class="dashicons dashicons-trash"></span></button></div>';
  }
  function hrimEscapeHtml(v){return $('<div>').text(v==null?'':String(v)).html();}
  function hrimEscapeAttr(v){return hrimEscapeHtml(v).replace(/"/g,'&quot;');}
  $(document).on('click','#hrim-add-room-question',function(){ $('#hrim-room-question-rows').append(roomQuestionRow()); });
  $(document).on('click','.hrim-remove-room-question',function(){
    var $rows=$('#hrim-room-question-rows .hrim-room-question-row');
    if($rows.length===1){$(this).closest('.hrim-room-question-row').find('input[type=text],input[name="additional_question[]"],select').val('');$(this).closest('.hrim-room-question-row').find('input[name="additional_id[]"]').val('0');return;}
    $(this).closest('.hrim-room-question-row').remove();
  });
})(jQuery);

(function($){
  function hrimSetLinkedOptionVisibility($select,matcher){
    if(!$select.length)return;
    var current=String($select.val()||''),valid=false;
    $select.find('option').each(function(){
      if(!this.value){$(this).prop('hidden',false).prop('disabled',false);return;}
      var show=!!matcher($(this));
      $(this).prop('hidden',!show).prop('disabled',!show);
      if(show&&String(this.value)===current)valid=true;
    });
    if(current&&!valid)$select.val('');
    $select.trigger('hrim-linked-refresh');
  }
  function hrimFilterIssueLocation(source){
    var h=String($('#hrimIssueHotel').val()||''),f=String($('#hrimIssueFloor').val()||'');
    hrimSetLinkedOptionVisibility($('#hrimIssueFloor'),function($option){return !h||String($option.data('hotel')||'')===h;});
    f=String($('#hrimIssueFloor').val()||'');
    hrimSetLinkedOptionVisibility($('#hrimIssueRoom'),function($option){
      var hotelOk=!h||String($option.data('hotel')||'')===h;
      var floorOk=!f||String($option.data('floor')||'')===f;
      return hotelOk&&floorOk;
    });
    if(source==='hotel'&&!$('#hrimIssueFloor').val())$('#hrimIssueRoom').val('');
  }
  $(document).on('change','#hrimIssueHotel',function(){hrimFilterIssueLocation('hotel');});
  $(document).on('change','#hrimIssueFloor',function(){hrimFilterIssueLocation('floor');});
  $(function(){hrimFilterIssueLocation('init');});
})(jQuery);

/* v3.9.19: filters stay open after use and close only from the Filter icon */
(function($){
  $(function(){
    $('.hrim-content .hrim-panel.hrim-no-print').each(function(index){
      var $panel=$(this), $form=$panel.find('form.hrim-filter').first();
      if(!$form.length || $panel.data('hrim-filter-enhanced')) return;

      var targetId='hrimFilterPanel'+index;
      var storageKey='hrim_filter_open:'+window.location.pathname+':'+index;
      var savedOpen=false;
      try { savedOpen=sessionStorage.getItem(storageKey)==='1'; } catch(e) {}

      $panel.data('hrim-filter-enhanced',1)
        .addClass('hrim-collapsible-filter-panel')
        .toggleClass('is-open',savedOpen)
        .attr('aria-hidden',savedOpen?'false':'true')
        .attr('id',targetId)
        .data('hrim-filter-storage-key',storageKey);

      var $bar=$('<div class="hrim-filter-action-bar hrim-no-print"></div>');

      /* v3.15.11: keep the table search permanently visible to the left of Download. */
      var $search=$form.find('input.hrim-table-search').first();
      if($search.length){
        var $searchLabel=$search.closest('label');
        $search.detach().attr({placeholder:'','aria-label':'Search'}).addClass('hrim-header-search');
        if($searchLabel.length){ $searchLabel.remove(); }
        $bar.append($search);
      }

      var $excel=$form.find('.hrim-export-csv').first();
      if($excel.length){
        $excel.detach().addClass('hrim-icon-only-button').attr({'title':'Download Excel','aria-label':'Download Excel'}).html('<span class="dashicons dashicons-download" aria-hidden="true"></span>');
        $bar.append($excel);
      }

      var $toggle=$('<button type="button" class="hrim-filter-toggle" aria-controls="'+targetId+'"><span class="dashicons dashicons-filter" aria-hidden="true"></span></button>');
      $toggle.toggleClass('is-open',savedOpen)
        .attr('aria-expanded',savedOpen?'true':'false')
        .attr('title',savedOpen?'Hide Filters':'Show Filters')
        .attr('aria-label',savedOpen?'Hide Filters':'Show Filters');
      $bar.append($toggle);

      /* Keep the actions inside the result card, beside the record count. */
      var $resultPanel=$panel.nextAll('.hrim-panel').first();
      if($resultPanel.length){
        var $head=$resultPanel.children('.hrim-panel-head').first();
        if(!$head.length){
          var $table=$resultPanel.find('table.hrim-table').first();
          var rowCount=$table.find('tbody>tr').filter(function(){
            return !$(this).hasClass('hrim-section-detail-row') && !$(this).find('td.hrim-empty').length;
          }).length;
          $head=$('<div class="hrim-panel-head hrim-results-panel-head"><div><small>'+rowCount+' records</small></div></div>');
          $resultPanel.prepend($head);
        }
        var $right=$head.children('.hrim-toolbar,.hrim-panel-head-actions').first();
        if(!$right.length){
          $right=$('<div class="hrim-toolbar hrim-panel-head-actions"></div>');
          $head.append($right);
        }
        $right.prepend($bar);
      } else {
        $panel.before($bar);
      }
    });

    $(document).on('click','.hrim-filter-toggle',function(){
      var $btn=$(this), id=$btn.attr('aria-controls'), $panel=$('#'+id);
      var open=!$panel.hasClass('is-open');
      $panel.toggleClass('is-open',open).attr('aria-hidden',open?'false':'true');
      $btn.toggleClass('is-open',open)
        .attr('aria-expanded',open?'true':'false')
        .attr('title',open?'Hide Filters':'Show Filters')
        .attr('aria-label',open?'Hide Filters':'Show Filters');
      try { sessionStorage.setItem($panel.data('hrim-filter-storage-key'),open?'1':'0'); } catch(e) {}
    });
  });



  // Searchable checkbox multi-select used by User Departments.
  function hrimUpdateDepartmentMultiSelect($root){
    var names=[];
    $root.find('.hrim-multiselect-option input:checked').each(function(){ names.push($(this).siblings('span').text().trim()); });
    var text=names.length===0?($root.data('placeholder')||'Select'):names.join(', ');
    $root.find('.hrim-multiselect-value').text(text);
  }
  $('.hrim-multiselect').each(function(){ hrimUpdateDepartmentMultiSelect($(this)); });
  $(document).on('click','.hrim-multiselect-toggle',function(e){
    e.preventDefault(); e.stopPropagation();
    var $root=$(this).closest('.hrim-multiselect');
    $('.hrim-multiselect').not($root).removeClass('is-open').find('.hrim-multiselect-panel').prop('hidden',true);
    var open=!$root.hasClass('is-open');
    $root.toggleClass('is-open',open).find('.hrim-multiselect-panel').prop('hidden',!open);
    $(this).attr('aria-expanded',open?'true':'false');
    if(open) $root.find('.hrim-multiselect-search').trigger('focus');
  });
  $(document).on('click','.hrim-multiselect-panel, .hrim-multiselect-option, .hrim-multiselect-search, .hrim-multiselect-tools, .hrim-multiselect-tools button',function(e){ e.stopPropagation(); });
  /* v3.14.69: multiselect checkbox dropdowns stay open while working inside them and close only on outside click. */
  $(document).on('input','.hrim-multiselect-search',function(){
    var q=String($(this).val()||'').toLowerCase();
    $(this).closest('.hrim-multiselect').find('.hrim-multiselect-option').each(function(){ $(this).toggleClass('is-hidden',q && $(this).text().toLowerCase().indexOf(q)===-1); });
  });
  $(document).on('change','.hrim-multiselect-option input',function(){ hrimUpdateDepartmentMultiSelect($(this).closest('.hrim-multiselect')); });
  $(document).on('click','.hrim-multiselect-all',function(){
    var $root=$(this).closest('.hrim-multiselect');
    $root.find('.hrim-multiselect-option:not(.is-hidden) input').prop('checked',true);
    hrimUpdateDepartmentMultiSelect($root);
  });
  $(document).on('click','.hrim-multiselect-clear',function(){
    var $root=$(this).closest('.hrim-multiselect');
    $root.find('.hrim-multiselect-option input').prop('checked',false);
    hrimUpdateDepartmentMultiSelect($root);
  });
  $(document).on('submit','.hrim-modal .hrim-form',function(e){
    var $multi=$(this).find('.hrim-multiselect');
    if($multi.length && !$multi.find('input[type=checkbox]:checked').length){
      e.preventDefault();
      $multi.addClass('is-open').find('.hrim-multiselect-panel').prop('hidden',false);
      $multi.find('.hrim-multiselect-toggle').attr('aria-expanded','true').trigger('focus');
      alert('Please select at least one Department.');
    }
  });

  // Department-linked manual issue fields.
  function hrimFilterIssueDepartment(){
    var department=String($('#hrimIssueDepartment').val()||'');
    var $section=$('#hrimIssueSection'),sectionValue=String($section.val()||''),sectionValid=false;
    if($section.length){
      $section.find('option').each(function(){
        if(!this.value){$(this).prop('hidden',false).prop('disabled',false);return;}
        var show=!department||String($(this).data('department')||'')===department;
        $(this).prop('hidden',!show).prop('disabled',!show);
        if(show&&String(this.value)===sectionValue)sectionValid=true;
      });
      if(sectionValue&&!sectionValid)$section.val('');
      $section.trigger('hrim-linked-refresh');
    }
    var $assigned=$('#hrimAddIssueForm select[name=assigned_to]'),assignedValue=String($assigned.val()||''),assignedValid=false;
    $assigned.find('option').each(function(){
      if(!this.value){$(this).prop('hidden',false).prop('disabled',false);return;}
      var global=String($(this).data('global')||'')==='1';
      var departments=String($(this).data('departments')||'').split(',').filter(Boolean);
      var show=global||!department||departments.indexOf(department)>-1;
      $(this).prop('hidden',!show).prop('disabled',!show);
      if(show&&String(this.value)===assignedValue)assignedValid=true;
    });
    if(assignedValue&&!assignedValid)$assigned.val('');
    $assigned.trigger('hrim-linked-refresh');
  }
  $(document).on('change','#hrimIssueDepartment',hrimFilterIssueDepartment);
  hrimFilterIssueDepartment();
})(jQuery);

/* Universal searchable dropdowns for all RoomScore forms and filters. */
(function($){
  'use strict';

  function escHtml(value){
    return String(value == null ? '' : value)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
  }

  function optionLabel(option){
    return $.trim($(option).text());
  }

  function selectedText($select){
    if($select.prop('multiple')){
      var labels=[];
      $select.find('option:selected').each(function(){
        if(this.value) labels.push(optionLabel(this));
      });
      if(!labels.length) return '';
      return labels.join(', ');
    }
    var $opt=$select.find('option:selected').first();
    return $opt.length && $opt.val() ? optionLabel($opt) : ($select.data('search-placeholder') || optionLabel($select.find('option').first()) || 'Select');
  }

  function renderOptions($select,$root){
    var multiple=$select.prop('multiple');
    var html='';
    $select.find('option').each(function(index){
      var option=this;
      var value=String(option.value || '');
      var label=optionLabel(option);
      var disabled=option.disabled;
      var hidden=option.hidden || $(option).css('display')==='none';
      if(hidden) return;
      var selected=option.selected;
      var placeholder=!value && index===0;
      html += '<button type="button" class="hrim-search-select-option'+(selected?' is-selected':'')+(disabled?' is-disabled':'')+(placeholder?' is-placeholder':'')+'" data-value="'+escHtml(value)+'" '+(disabled?'disabled':'')+'>';
      if(multiple && !placeholder){
        html += '<span class="hrim-search-select-check">'+(selected?'✓':'')+'</span>';
      }
      html += '<span>'+escHtml(label)+'</span></button>';
    });
    $root.find('.hrim-search-select-options').html(html);
    $root.find('.hrim-search-select-value').text(selectedText($select));
  }

  $(document).on('hrim-linked-refresh','select',function(){
    var $select=$(this),$root=$select.next('.hrim-search-select');
    if($root.length)renderOptions($select,$root);
  });

  function closeAll(except){
    $('.hrim-search-select.is-open').each(function(){
      if(except && this===except) return;
      $(this).removeClass('is-open').find('.hrim-search-select-panel').prop('hidden',true);
      $(this).find('.hrim-search-select-toggle').attr('aria-expanded','false');
    });
  }

  function enhanceSelect(select){
    var $select=$(select);
    if($select.data('hrim-search-enhanced')) return;
    if($select.closest('.hrim-multiselect').length) return;
    if($select.attr('size') && !$select.prop('multiple')) return;

    $select.data('hrim-search-enhanced',true).addClass('hrim-native-search-select');
    var multiple=$select.prop('multiple');
    if(multiple && String($select.attr('data-initial-empty') || '') === '1'){
      $select.find('option').prop('selected',false);
      $select.removeAttr('data-initial-empty');
    }
    var placeholder=$select.attr('data-placeholder') || optionLabel($select.find('option').first()) || 'Select';
    $select.data('search-placeholder',placeholder);

    var $root=$('<div class="hrim-search-select'+(multiple?' is-multiple':'')+'"></div>');
    var $toggle=$('<button type="button" class="hrim-search-select-toggle" aria-expanded="false"><span class="hrim-search-select-value"></span><span class="hrim-search-select-arrow">⌄</span></button>');
    var $panel=$('<div class="hrim-search-select-panel" hidden><input type="search" class="hrim-search-select-search" placeholder="Search..."><div class="hrim-search-select-options"></div></div>');
    $root.append($toggle,$panel);
    $select.after($root);
    renderOptions($select,$root);

    var observer=new MutationObserver(function(){ renderOptions($select,$root); });
    observer.observe(select,{childList:true,subtree:true,attributes:true,attributeFilter:['selected','disabled','hidden','label']});
    $select.data('hrim-search-observer',observer);
  }

  function enhanceAll(context){
    $(context || document).find('select').each(function(){ enhanceSelect(this); });
  }

  $(document).on('click','.hrim-search-select-toggle',function(e){
    e.preventDefault(); e.stopPropagation();
    var $root=$(this).closest('.hrim-search-select');
    var open=!$root.hasClass('is-open');
    closeAll(open?$root[0]:null);
    $root.toggleClass('is-open',open);
    $root.find('.hrim-search-select-panel').prop('hidden',!open);
    $(this).attr('aria-expanded',open?'true':'false');
    if(open){
      var $search=$root.find('.hrim-search-select-search');
      $search.val('').trigger('input').trigger('focus');
    }
  });

  $(document).on('input','.hrim-search-select-search',function(){
    var query=$(this).val().toLowerCase().trim();
    $(this).closest('.hrim-search-select-panel').find('.hrim-search-select-option').each(function(){
      var text=$(this).text().toLowerCase();
      $(this).toggle(!query || text.indexOf(query)!==-1);
    });
  });

  $(document).on('click','.hrim-search-select-panel, .hrim-search-select-search, .hrim-search-select-options',function(e){ e.stopPropagation(); });

  $(document).on('click','.hrim-search-select-option',function(e){
    e.preventDefault();
    var $option=$(this);
    if($option.hasClass('is-disabled')) return;
    var $root=$option.closest('.hrim-search-select');
    var $select=$root.prev('select.hrim-native-search-select');
    var value=String($option.data('value') == null ? '' : $option.data('value'));

    if($select.prop('multiple')){
      var $native=$select.find('option').filter(function(){ return String(this.value)===value; }).first();
      if(!$native.length || !value) return;
      $native.prop('selected',!$native.prop('selected'));
      $select.trigger('change');
      renderOptions($select,$root);
    } else {
      $select.val(value).trigger('change');
      renderOptions($select,$root);
      closeAll();
    }
  });

  $(document).on('change','select.hrim-native-search-select',function(){
    var $select=$(this), $root=$select.next('.hrim-search-select');
    if($root.length) renderOptions($select,$root);
  });

  $(document).on('click',function(e){
    if(!$(e.target).closest('.hrim-search-select').length) closeAll();
  });

  $(function(){
    enhanceAll(document);
    var bodyObserver=new MutationObserver(function(mutations){
      mutations.forEach(function(mutation){
        $(mutation.addedNodes).each(function(){
          if(this.nodeType!==1) return;
          if($(this).is('select')) enhanceSelect(this);
          enhanceAll(this);
        });
      });
    });
    bodyObserver.observe(document.body,{childList:true,subtree:true});
  });
})(jQuery);

/* Occupancy Check auto-save */
(function($){
  function spinner(show){
    var $s=$('#hrimGlobalSpinner');
    if(!$s.length) return;
    $s.toggleClass('is-active',!!show).removeClass('is-visible').attr('aria-hidden',show?'false':'true');
  }
  function notice(message,type){
    var $n=$('<div class="hrim-toast '+(type||'success')+'"></div>').text(message).appendTo('body');
    setTimeout(function(){ $n.addClass('is-show'); },20);
    setTimeout(function(){ $n.removeClass('is-show'); setTimeout(function(){$n.remove();},250); },2500);
  }
  $(document).on('click','.hrim-occ-status-btn',function(){
    var $button=$(this), $group=$button.closest('.hrim-occ-status-buttons'), $row=$button.closest('tr');
    if($button.prop('disabled') || $group.hasClass('is-saving') || $group.hasClass('is-locked')) return;
    var status=$button.data('status');
    if(status!=='yes' && status!=='no') return;

    var previous=$group.find('.hrim-occ-status-btn.is-selected').data('status') || '';
    spinner(true);
    $group.addClass('is-saving').find('.hrim-occ-status-btn').prop('disabled',true);

    $.post(HRIM.ajax,{action:'hrim_save_occupancy_room',nonce:HRIM.nonce,entry_id:$row.data('entry-id'),status:status})
      .done(function(res){
        if(!res || !res.success){
          notice(res&&res.data&&res.data.message?res.data.message:'Unable to save.','error');
          if(res&&res.data&&res.data.locked){
            $group.addClass('is-locked');
            if(!$group.next('.hrim-lock-note').length) $group.after('<small class="hrim-lock-note">Locked</small>');
          } else {
            $group.find('.hrim-occ-status-btn').prop('disabled',false);
          }
          return;
        }
        $group.find('.hrim-occ-status-btn').removeClass('is-selected');
        $button.addClass('is-selected');
        $row.find('.hrim-occ-time').text(res.data.time);
        $row.find('.hrim-occ-user').text(res.data.user);

        // Refresh the selected floor progress immediately without reloading.
        var $panel=$row.closest('.hrim-occ-floor-room-panel');
        if($panel.length){
          var floorId=String($panel.data('floor-panel'));
          var total=$panel.find('tbody tr[data-entry-id]').length;
          var answered=$panel.find('tbody tr[data-entry-id]').filter(function(){
            return $(this).find('.hrim-occ-status-btn.is-selected').length>0;
          }).length;
          var $floorButton=$('.hrim-view-floor-rooms[data-floor-id="'+floorId+'"]');
          if($floorButton.length){
            var floorName=$floorButton.data('floor-name') || 'Floor';
            $floorButton.find('.hrim-occ-floor-name').text(floorName+' ('+answered+'/'+total+')');
          }
        }

        notice('Room status saved.');
        $group.find('.hrim-occ-status-btn').prop('disabled',false);
        clearTimeout($group.data('lock-timer'));
        var timer=setTimeout(function(){
          $group.addClass('is-locked').find('.hrim-occ-status-btn').prop('disabled',true);
          if(!$group.next('.hrim-lock-note').length) $group.after('<small class="hrim-lock-note">Locked</small>');
        },300000);
        $group.data('lock-timer',timer);
      })
      .fail(function(){
        notice('Unable to save room status.','error');
        $group.find('.hrim-occ-status-btn').prop('disabled',false);
        $group.find('.hrim-occ-status-btn').removeClass('is-selected');
        if(previous) $group.find('[data-status="'+previous+'"]').addClass('is-selected');
      })
      .always(function(){ $group.removeClass('is-saving'); spinner(false); });
  });

  var occTimer=null;
  $(document).on('input change','.hrim-occ-system-count',function(){
    var $row=$(this).closest('tr');
    clearTimeout(occTimer);
    occTimer=setTimeout(function(){
      spinner(true);
      $.post(HRIM.ajax,{action:'hrim_save_occupancy_system',nonce:HRIM.nonce,session_id:$row.data('session-id'),system_count:$row.find('.hrim-occ-system-count').val()})
        .done(function(res){
          if(!res||!res.success){ notice(res&&res.data&&res.data.message?res.data.message:'Unable to save.','error'); return; }
          var key=String(res.data.status||'').toLowerCase();
          $row.find('.hrim-occ-system-status').html('<span class="hrim-status '+key+'">'+res.data.status+'</span>');
          $row.find('.hrim-occ-system-user-name').text(res.data.user || '—');
          notice('System Count saved.');
        }).fail(function(){notice('Unable to save system fields.','error');}).always(function(){spinner(false);});
    },450);
  });
})(jQuery);

/* Add Occupancy floor room popup */
(function($){
  $(document).on('click','.hrim-open-office-count-modal',function(){
    var $btn=$(this),$modal=$('#hrimOfficeCountModal');
    $('#hrimOfficeSessionId').val($btn.data('session-id'));
    $('#hrimOfficeDate').val($btn.data('date'));
    $('#hrimOfficeCount').val($btn.attr('data-count')||'').trigger('focus');
    $modal.addClass('open').attr('aria-hidden','false');
  });

  $(document).on('click','.hrim-view-floor-rooms',function(){
    var floorId=String($(this).data('floor-id'));
    var floorName=$(this).data('floor-name') || 'Floor';
    var $modal=$('#hrimOccupancyRoomsModal');
    $modal.find('.hrim-occ-floor-room-panel').prop('hidden',true);
    $modal.find('[data-floor-panel="'+floorId+'"]').prop('hidden',false);
    $modal.find('.hrim-occ-modal-title').text(floorName+' - Rooms');
    $modal.addClass('open').attr('aria-hidden','false');
  });
  $(document).on('click','#hrimOccupancyRoomsModal .hrim-modal-close',function(){
    $('#hrimOccupancyRoomsModal').removeClass('open').attr('aria-hidden','true');
  });
  $(document).on('click','#hrimOccupancyRoomsModal',function(e){
    if(e.target===this) $(this).removeClass('open').attr('aria-hidden','true');
  });
})(jQuery);


/* v3.14.66: required-field stars and reliable outside-click closing. */
(function($){
  'use strict';

  function markRequiredStars(context){
    function isControlNode(node){
      return node && node.nodeType===1 && (
        /^(INPUT|SELECT|TEXTAREA|BUTTON)$/.test(node.tagName) ||
        node.classList.contains('hrim-search-select') ||
        node.classList.contains('hrim-multiselect') ||
        node.classList.contains('select2') ||
        node.classList.contains('select2-container') ||
        !!node.querySelector('input,select,textarea')
      );
    }

    $(context || document).find('label, legend, .hrim-field-label').each(function(){
      var el=this;
      if($(el).find('> .hrim-label-title').length) return;
      var hasRequired=$(el).find('input[required],select[required],textarea[required]').length>0;
      var textHasStar=/\*/.test($(el).clone().children().remove().end().text());
      if(!hasRequired && !textHasStar) return;

      if(el.matches && el.matches('.hrim-field-label, legend')){
        var rawText=$(el).text().replace(/\s*\*\s*/g,' ').replace(/\s+/g,' ').trim();
        if(!rawText) return;
        var title=document.createElement('span');
        title.className='hrim-label-title';
        title.appendChild(document.createTextNode(rawText + ' '));
        var star=document.createElement('span');
        star.className='hrim-required-mark';
        star.textContent='*';
        title.appendChild(star);
        el.innerHTML='';
        el.appendChild(title);
        return;
      }

      var beforeNodes=[];
      var firstControl=null;
      Array.prototype.slice.call(el.childNodes).forEach(function(node){
        if(firstControl) return;
        if(isControlNode(node)){
          firstControl=node;
          return;
        }
        beforeNodes.push(node);
      });

      var raw='';
      beforeNodes.forEach(function(node){
        if(node.nodeType===3) raw += node.nodeValue;
        else if(node.nodeType===1 && !isControlNode(node)) raw += ' ' + $(node).text();
      });
      raw=raw.replace(/\s*\*\s*/g,' ').replace(/\s+/g,' ').trim();
      if(!raw) return;

      beforeNodes.forEach(function(node){ el.removeChild(node); });

      var title=document.createElement('span');
      title.className='hrim-label-title';
      title.appendChild(document.createTextNode(raw + ' '));
      var star=document.createElement('span');
      star.className='hrim-required-mark';
      star.textContent='*';
      title.appendChild(star);
      el.insertBefore(title, firstControl || el.firstChild);
    });
  }

  function closeCheckboxDropdowns(target){
    if(!$(target).closest('.hrim-multiselect').length){
      $('.hrim-multiselect').removeClass('is-open')
        .find('.hrim-multiselect-panel').prop('hidden',true);
      $('.hrim-multiselect-toggle').attr('aria-expanded','false');
    }
    if(!$(target).closest('.hrim-search-select').length){
      $('.hrim-search-select.is-open').removeClass('is-open')
        .find('.hrim-search-select-panel').prop('hidden',true);
      $('.hrim-search-select-toggle').attr('aria-expanded','false');
    }
  }

  $(function(){ markRequiredStars(document); });
  document.addEventListener('pointerdown',function(e){ closeCheckboxDropdowns(e.target); },true);
  document.addEventListener('touchstart',function(e){ closeCheckboxDropdowns(e.target); },true);

  var observer=new MutationObserver(function(mutations){
    mutations.forEach(function(mutation){
      Array.prototype.forEach.call(mutation.addedNodes,function(node){
        if(node.nodeType===1) markRequiredStars(node);
      });
    });
  });
  $(function(){ if(document.body) observer.observe(document.body,{childList:true,subtree:true}); });
})(jQuery);


/* v3.14.79: Learn tab progress and accordion interaction. */
(function($){
  'use strict';
  function learnValues(){
    return $('.hrim-learn-check:checked').map(function(){ return this.value; }).get();
  }
  function updateLearnUI(save){
    var total=$('.hrim-learn-check').length;
    var values=learnValues();
    var completed=values.length;
    var percent=total?Math.round((completed/total)*100):0;
    $('#hrimLearnCompleted').text(completed);
    $('#hrimLearnPercent').text(percent+'%');
    $('#hrimLearnProgressBar').css('width',percent+'%');
    $('#hrimLearnComplete').prop('hidden',completed!==total);
    $('.hrim-learn-check').each(function(){ $(this).closest('.hrim-learn-step').toggleClass('is-complete',this.checked); });
    if(save && window.HRIM){
      $.post(HRIM.ajax,{action:'hrim_save_learn_progress',nonce:HRIM.nonce,steps:values});
    }
  }
  $(document).on('click','.hrim-learn-module-head',function(){
    var $head=$(this), $steps=$head.next('.hrim-learn-steps'), open=$steps.prop('hidden');
    $steps.prop('hidden',!open);
    $head.attr('aria-expanded',open?'true':'false');
  });
  $(document).on('change','.hrim-learn-check',function(){ updateLearnUI(true); });
  $(document).on('click','#hrimLearnReset',function(){
    if(!window.confirm('Reset all learning progress?')) return;
    $('.hrim-learn-check').prop('checked',false);
    updateLearnUI(true);
  });
  $(function(){ if($('.hrim-learn-page').length) updateLearnUI(false); });
})(jQuery);


/* v3.14.97: top-page search boxes intentionally have no hint text. */
(function($){
  'use strict';
  function clearTopSearchHints(context){
    $(context || document).find('input.hrim-table-search').attr('placeholder','');
  }
  $(function(){ clearTopSearchHints(document); });
  var observer=new MutationObserver(function(mutations){
    mutations.forEach(function(mutation){
      Array.prototype.forEach.call(mutation.addedNodes,function(node){
        if(node.nodeType===1) clearTopSearchHints(node);
      });
    });
  });
  $(function(){ if(document.body) observer.observe(document.body,{childList:true,subtree:true}); });
})(jQuery);

/* RoomScore v3.15.12: guarantee a visible search field immediately before Download in every table header. */
(function($){
  function ensureHeaderSearch(context){
    $(context || document).find('.hrim-panel-head').each(function(){
      var $head=$(this);
      if($head.closest('.hrim-dashboard-panel,.hrim-communications-grid').length){ $head.find('input.hrim-table-search').remove(); $head.find('.hrim-export-csv,.hrim-icon-only-button[aria-label="Download Excel"]').remove(); return; }
      var $download=$head.find('.hrim-export-csv,.hrim-icon-only-button[aria-label="Download Excel"]').first();
      if(!$download.length || $head.find('input.hrim-table-search').length) return;
      var $search=$('<input type="search" class="hrim-table-search hrim-always-visible-search" placeholder="" aria-label="Search">');
      $download.before($search);
    });
  }
  $(function(){
    ensureHeaderSearch(document);
    setTimeout(function(){ ensureHeaderSearch(document); },150);
  });
  $(document).on('hrim:table-rendered',function(e,context){ ensureHeaderSearch(context || document); });
})(jQuery);

/* v3.15.18: Dashboard tables intentionally do not show search controls. */
(function($){
  function removeDashboardSearch(context){
    $(context || document).find('.hrim-dashboard-panel input.hrim-table-search').remove();
    $(context || document).find('.hrim-dashboard-panel .hrim-universal-table-filters').remove();
  }
  $(function(){ removeDashboardSearch(document); setTimeout(function(){ removeDashboardSearch(document); },250); });
  $(document).on('hrim:table-rendered',function(e,context){ removeDashboardSearch(context || document); });
})(jQuery);

/* v3.15.30: responsive two-column mobile/tablet View Access table. */
(function($){
  'use strict';
  function isCompact(){
    return Math.min(window.innerWidth||9999,document.documentElement.clientWidth||9999)<=1024;
  }
  function applyViewAccessLayout(){
    var compact=isCompact();
    $('body').toggleClass('hrim-view-access-compact',compact);
    $('.hrim-user-view-permissions').each(function(){
      var $wrap=$(this);
      $wrap.find('.hrim-view-access-desktop-wrap .hrim-view-checkbox').prop('disabled',compact);
      $wrap.find('.hrim-view-access-mobile-wrap .hrim-view-checkbox').prop('disabled',!compact);
    });
  }
  function syncMatching($source){
    var value=$source.val(),checked=$source.is(':checked');
    $source.closest('.hrim-user-view-permissions').find('.hrim-view-checkbox').filter(function(){
      return this.value===value&&this!==$source[0];
    }).prop('checked',checked);
  }
  function syncSelectAll($wrap){
    var $visible=$wrap.find('.hrim-view-checkbox:enabled');
    var $all=$wrap.find('.hrim-view-select-all-checkbox');
    if(!$visible.length||!$all.length)return;
    var checked=$visible.filter(':checked').length;
    $all.prop('checked',checked===$visible.length).prop('indeterminate',checked>0&&checked<$visible.length);
  }
  $(document).on('change','.hrim-view-select-all-checkbox',function(){
    var $wrap=$(this).closest('.hrim-user-view-permissions'),checked=this.checked;
    $wrap.find('.hrim-view-checkbox').prop('checked',checked);
    $(this).prop('indeterminate',false);
  });
  $(document).on('change','.hrim-view-checkbox',function(){
    var $box=$(this),$wrap=$box.closest('.hrim-user-view-permissions');
    syncMatching($box);
    syncSelectAll($wrap);
  });
  $(function(){
    applyViewAccessLayout();
    $('.hrim-user-view-permissions').each(function(){syncSelectAll($(this));});
  });
  $(window).on('resize orientationchange',function(){
    applyViewAccessLayout();
    $('.hrim-user-view-permissions').each(function(){syncSelectAll($(this));});
  });
})(jQuery);


/* v3.15.62: restore issue modal context for inline form messages */
jQuery(function($){
  var params=new URLSearchParams(window.location.search), form=params.get('hrim_form'), issueId=params.get('hrim_issue_id');
  if(!form || !issueId) return;
  var selector='';
  if(form==='assign') selector='.hrim-assign-issue[data-id="'+issueId+'"]';
  if(form==='edit') selector='.hrim-edit-issue[data-id="'+issueId+'"]';
  if(form==='progress') selector='.hrim-progress-issue[data-id="'+issueId+'"]';
  var $btn=selector?$(selector).first():$();
  if($btn.length){
    window.hrimRestoringIssueForm=true;
    $btn.trigger('click');
    window.hrimRestoringIssueForm=false;
  }
});

/* v3.15.67: clear saved issue form message after the modal is closed */
jQuery(function($){
  $(document).on('click','#hrimProgressIssueModal .hrim-modal-close',function(){
    $('#hrimProgressIssueModal .hrim-form-alert').remove();
    if(window.history && window.history.replaceState){
      var url=new URL(window.location.href);
      ['saved','error','hrim_msg','hrim_form','hrim_issue_id','hrim_saved_status','hrim_refresh'].forEach(function(key){url.searchParams.delete(key);});
      window.history.replaceState({},document.title,url.pathname+(url.search?'?'+url.searchParams.toString():'')+url.hash);
    }
  });
});


/* v3.15.63: show success, error and validation messages inside the relevant view card/form */
(function($){
  'use strict';
  function placeViewMessages(){
    var $content=$('.hrim-content').first();
    if(!$content.length) return;
    var $alerts=$content.children('.hrim-alert');
    if(!$alerts.length) return;

    var $target=$content.find('.hrim-modal.open .hrim-modal-card').first();
    if(!$target.length) $target=$content.find('.hrim-panel').first();
    if(!$target.length) $target=$content.find('.hrim-settings-card,.hrim-card,.hrim-subpanel').first();
    if(!$target.length) return;

    var $heading=$target.children('h1,h2,h3,.hrim-panel-head').first();
    $alerts.addClass('hrim-inline-view-message');
    if($heading.length){
      $alerts.insertAfter($heading);
    }else{
      $alerts.prependTo($target);
    }
  }
  $(function(){
    placeViewMessages();
    setTimeout(placeViewMessages,100);
  });
})(jQuery);


/* v3.15.66: keep submitted issue status visible after save */
jQuery(function($){
  var params=new URLSearchParams(window.location.search);
  if(params.get('hrim_form')!=='progress') return;
  var savedStatus=params.get('hrim_saved_status');
  if(savedStatus==='resolved' || savedStatus==='in_progress'){
    window.setTimeout(function(){
      $('#hrimProgressIssueStatus').val(savedStatus).trigger('change');
    },0);
  }
});


/* v3.15.95: user-facing terminology uses Asset/Assets, without changing saved master/data values such as Asset Type 'Room'. */
(function($){
  function assetTerminology(root){
    root=root||document.body;
    if(!root) return;
    var walker=document.createTreeWalker(root,NodeFilter.SHOW_TEXT,{acceptNode:function(node){
      var p=node.parentNode;
      if(!p || /^(SCRIPT|STYLE|TEXTAREA|OPTION)$/i.test(p.nodeName)) return NodeFilter.FILTER_REJECT;
      // Never rewrite record values displayed in tables or select options.
      // Example: an Asset Type entered as 'Room' must continue to display as 'Room'.
      if($(p).closest('td, option').length) return NodeFilter.FILTER_REJECT;
      return /\brooms?\b/i.test(node.nodeValue||'') ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
    }});
    var nodes=[]; while(walker.nextNode()) nodes.push(walker.currentNode);
    nodes.forEach(function(node){
      var text=node.nodeValue;
      var brand='__HRIM_ROOM_SCORE__';
      text=text.replace(/Room Score/g,brand)
               .replace(/\bRooms\b/g,'Assets').replace(/\bRoom\b/g,'Asset')
               .replace(/\brooms\b/g,'assets').replace(/\broom\b/g,'asset')
               .replace(new RegExp(brand,'g'),'Room Score');
      node.nodeValue=text;
    });
    $(root).find('input[placeholder],select[title],[title],[aria-label]').each(function(){
      ['placeholder','title','aria-label'].forEach(function(attr){
        var value=$(this).attr(attr); if(!value || !/\brooms?\b/i.test(value) || /Room Score/.test(value)) return;
        value=value.replace(/\bRooms\b/g,'Assets').replace(/\bRoom\b/g,'Asset').replace(/\brooms\b/g,'assets').replace(/\broom\b/g,'asset');
        $(this).attr(attr,value);
      },this);
    });
    // Do not rewrite option text because options may contain user-entered master values.
  }
  $(function(){
    assetTerminology(document.body);
    var observer=new MutationObserver(function(mutations){
      mutations.forEach(function(m){ Array.prototype.forEach.call(m.addedNodes||[],function(n){ if(n.nodeType===1) assetTerminology(n); else if(n.nodeType===3 && n.parentNode) assetTerminology(n.parentNode); }); });
    });
    observer.observe(document.body,{childList:true,subtree:true});
  });
})(jQuery);

/* v3.16.01: add asset-specific checkpoints directly under a selected section */
jQuery(function($){
  $(document).on('click','.hrim-section-add-checkpoint',function(){
    var sectionId=String($(this).data('section-id')||'');
    if(!sectionId) return;
    var $sectionRow=$(this).closest('tr');
    var $rows=$sectionRow.nextUntil('.hrim-applicable-section-row');
    var $anchor=$rows.length?$rows.last():$sectionRow;
    var row=''+
      '<tr class="hrim-new-checkpoint-row" data-section-id="'+sectionId+'">'+
        '<td><div class="hrim-new-checkpoint-wrap">'+
          '<input type="hidden" name="additional_id[]" value="0">'+
          '<input type="hidden" name="additional_section_id[]" value="'+sectionId+'">'+
          '<input type="text" name="additional_question[]" required placeholder="Enter new checkpoint">'+
        '</div></td>'+
        '<td class="hrim-checkpoint-show-col"><input type="checkbox" checked disabled aria-label="Show new checkpoint"></td>'+
        '<td class="hrim-checkpoint-action-col"><button type="button" class="hrim-remove-new-checkpoint" title="Remove" aria-label="Remove checkpoint"><span class="dashicons dashicons-no-alt"></span></button></td>'+
      '</tr>';
    var $row=$(row).insertAfter($anchor);
    $row.find('input[type=text]').trigger('focus');
  });
  $(document).on('click','.hrim-remove-new-checkpoint',function(){
    $(this).closest('tr').remove();
  });
});


/* v3.16.23: enforce checkpoint columns after dynamic/modal rendering */
(function($){
  function hrimFixCheckpointMobileGrid(){
    if(window.innerWidth>760) return;
    $('.hrim-applicable-checkpoints-table').each(function(){
      var table=this;
      table.style.setProperty('display','block','important');
      table.style.setProperty('width','100%','important');
      table.style.setProperty('min-width','0','important');
      $(table).find('thead,tbody').each(function(){
        this.style.setProperty('display','block','important');
        this.style.setProperty('width','100%','important');
      });
      $(table).find('thead tr, tbody tr').each(function(){
        if(this.classList.contains('hrim-applicable-section-row')){
          this.style.setProperty('display','block','important');
          this.style.setProperty('width','100%','important');
        }else{
          this.style.setProperty('display','grid','important');
          this.style.setProperty('grid-template-columns','minmax(0,1fr) 36px 36px','important');
          this.style.setProperty('width','100%','important');
          this.style.setProperty('min-width','0','important');
        }
      });
    });
  }
  $(document).ready(hrimFixCheckpointMobileGrid);
  $(document).on('click change ajaxComplete',function(){setTimeout(hrimFixCheckpointMobileGrid,30);});
  window.addEventListener('resize',hrimFixCheckpointMobileGrid);
})(jQuery);
