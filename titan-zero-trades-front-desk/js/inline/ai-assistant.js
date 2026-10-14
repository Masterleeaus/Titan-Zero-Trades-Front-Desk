(function($){
  function esc(s){ return $('<div>').text(s==null?'':s).html(); }
  function init($root){
    var cfg = JSON.parse($root.attr('data-cfg')||'{}');
    var $panel = $root.find('.tzfd-ai-panel');
    var $log   = $root.find('.tzfd-ai-log');
    var $input = $root.find('.tzfd-ai-input');
    var $typing= $root.find('.tzfd-ai-typing');
    var token  = null;
    var busy   = false;

    function push(role, text){
      var cls = role==='user' ? 'me' : (role==='system' ? 'sys' : 'bot');
      $log.append('<div class="tzfd-ai-msg '+cls+'">'+esc(text)+'</div>');
      $log.scrollTop($log[0].scrollHeight);
    }
    push('bot', cfg.greeting);

    $root.find('.tzfd-ai-bubble').on('click', function(){
      $panel.toggle(); if($panel.is(':visible')) $input.trigger('focus');
    });
    $root.find('.tzfd-ai-close').on('click', function(){
      cfg.floating ? $panel.hide() : null;
    });

    $root.find('.tzfd-ai-form').on('submit', function(e){
      e.preventDefault();
      if (busy) return;
      var text = $.trim($input.val());
      if (!text) return;
      push('user', text); $input.val(''); busy=true; $typing.prop('hidden', false);

      $.post(cfg.rest+'/message', {
        _wpnonce: cfg.nonce, token: token, formid: cfg.formid, message: text
      }).done(function(res){
        $typing.prop('hidden', true); busy=false;
        if (res && res.token) token = res.token;
        if (res && res.reply) push('bot', res.reply);
        if (res && res.tool_events) res.tool_events.forEach(function(ev){ push('system', '▸ '+ev); });
        if (res && res.done) {
          push('system', '✅ ' + (res.summary || 'Booked'));
          $input.prop('disabled', true).attr('placeholder','Conversation complete');
        }
      }).fail(function(xhr){
        $typing.prop('hidden', true); busy=false;
        push('system', '⚠️ '+(xhr.responseJSON&&xhr.responseJSON.message ? xhr.responseJSON.message : 'Please try again.'));
      });
    });

    $input.on('keydown', function(e){
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); $root.find('.tzfd-ai-form').trigger('submit'); }
    });

    // Photo → base64 → send as a message hint
    $root.find('.tzfd-ai-attach input').on('change', function(){
      var f = this.files && this.files[0]; if (!f) return;
      if (f.size > 4*1024*1024) { push('system','Photo too large (max 4 MB).'); return; }
      var r = new FileReader();
      r.onload = function(){
        // Upload to media library then send URL as a message
        $.post(cfg.rest+'/photo', { _wpnonce: cfg.nonce, token: token, formid: cfg.formid, data: r.result })
         .done(function(res){
           if (res && res.url) {
             push('user', '[photo attached]');
             $.post(cfg.rest+'/message', { _wpnonce: cfg.nonce, token: token, formid: cfg.formid, message: 'I attached a photo.', photo_url: res.url })
              .done(function(r2){ if(r2.reply) push('bot', r2.reply); });
           }
         }).fail(function(){ push('system','Could not upload photo.'); });
      };
      r.readAsDataURL(f);
    });
  }
  $(function(){ $('.tzfd-ai-root').each(function(){ init($(this)); }); });
})(jQuery);
