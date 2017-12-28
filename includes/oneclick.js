/* 
 * EasyTransac oneclick.js
 */

jQuery(function ($) {
    
    // Double load failsafe.
    var session_id = 'easytransac-oneclick' + Date.now();
    
    // Creates workspace.
    $('div.payment_method_easytransac').before($('<div id="'+session_id+'" class="payment_box payment_method_easytransac">'));

    // Unified OneClick loader
    // Requires : listcards_url
    //            oneclick_url
    //
    var listcards_url = '/?wc-api=easytransac&listcards=1';
    var oneclick_url = '/?wc-api=easytransac&oneclick=1';
    
    $('#'+session_id).html('<span id="etocloa001">OneClick loading ...</span>');
    
    // JSON Call
    $.getJSON(listcards_url, {}, buildFromJson);
    
    // Build  OneClick form from JSON.
    function buildFromJson(json) {
        
        $('#etocloa001').fadeOut().remove();
        
        if (!json.status || json.packet.length === 0) {
            
            // No cards available.
            $('#'+session_id).remove();
            return;
        }
        
        // Namespace
        var _space = $('#'+session_id);

        // Label
        _space.append($('<span style="width:100px;" title="Direct credit card payment">OneClick : </span>'));

        // Dropdown
        _space.append($('<select id="etalcadd001" name="oneclick_alias" style="width:200px; margin-left:10px;">'));
        $.each(json.packet, function (i, row) {
            $('#etalcadd001')
                .append($('<option value="' + row.Alias + '">' + row.CardNumber + '</option>'));
        });

        // Button
        _space.append($(' <input type="submit" id="etocbu001" class="button alt" style="width:150px; margin-left:15px;" value="OneClick pay">'));

        // Button click/*
        $('#etocbu001').click(function (e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            _space.append($('<input type="hidden" class="etocflag001" name="is_oneclick" value="1">'));
            $(this).submit();
            $('.etocflag001').remove();
        });
    }
});