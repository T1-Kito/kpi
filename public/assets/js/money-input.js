(function(){
    const selector='input[name="amount"],input[name="credit_limit"],input[name="sales_amount_threshold"],input[name="unit_price[]"],input[data-deal-item-price],input[data-quick-customer-credit-limit],input[data-approval-minimum]';
    function parse(value){
        if(value==null || value==='')return 0;
        if(typeof value==='number'){if(!Number.isFinite(value)||value<0)throw new Error('Số tiền không hợp lệ.');return value;}
        const text=String(value).trim();
        if(!/^(?:\d+|\d{1,3}(?:\.\d{3})*)(?:,\d{0,2})?$/.test(text))throw new Error('Nhập số tiền hợp lệ, dùng dấu phẩy cho phần lẻ.');
        const n=Number(text.replace(/\./g,'').replace(',','.'));
        if(!Number.isFinite(n)||n>Number.MAX_SAFE_INTEGER)throw new Error('Số tiền vượt giới hạn cho phép.');return n;
    }
    function format(value){
        if(value===''||value==null)return '';
        const text=typeof value==='number'?String(value).replace('.',','):String(value);
        const [integer,fraction]=text.replace(/\./g,'').split(',');
        const grouped=integer.replace(/^0+(?=\d)/,'').replace(/\B(?=(\d{3})+(?!\d))/g,'.');
        return grouped+(fraction===undefined?'':','+fraction);
    }
    function enhance(input){
        if(input.dataset.moneyReady)return;
        const raw=input.value;input.type='text';input.inputMode='decimal';input.autocomplete='off';input.dataset.moneyReady='1';
        input.value=raw===''?'':format(Number(raw));
    }
    function scan(root){if(root.matches?.(selector))enhance(root);root.querySelectorAll?.(selector).forEach(enhance);}
    window.VKMoney={parse,format};
    document.addEventListener('input',event=>{
        const input=event.target;if(!input.matches(selector))return;enhance(input);
        const position=input.selectionStart??input.value.length;
        const chars=input.value.slice(0,position).replace(/\./g,'').length;
        if(!/^[\d.]*(?:,\d{0,2})?$/.test(input.value)){input.setCustomValidity('Chỉ nhập chữ số, tối đa một dấu phẩy và hai số phần lẻ.');return;}
        // Typing another digit into 1.000 temporarily produces 1.0000.
        // Regroup first; validating this transient string leaves both the input
        // and dependent totals stuck at the previous amount.
        const candidate=format(input.value);
        try{parse(candidate);input.setCustomValidity('');}catch(error){input.setCustomValidity(error.message);return;}
        input.value=candidate;let caret=0,count=0;
        while(caret<input.value.length&&count<chars){if(input.value[caret]!=='.')count++;caret++;}
        input.setSelectionRange(caret,caret);
    },true);
    document.addEventListener('focusin',event=>{if(event.target.matches(selector))enhance(event.target);});
    scan(document);
    new MutationObserver(records=>{for(const record of records)for(const node of record.addedNodes)if(node.nodeType===1)scan(node);}).observe(document.body,{childList:true,subtree:true});
})();
