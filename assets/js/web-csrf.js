/** Supply the session token to existing fetch, jQuery/XHR and native web forms. */
(() => {
    'use strict';
    const token=document.querySelector('meta[name="web-csrf-token"]')?.content;
    if(!token)return;
    const unsafe=method=>!['GET','HEAD','OPTIONS'].includes(String(method||'GET').toUpperCase());
    const sameOrigin=url=>{try{return new URL(url,location.href).origin===location.origin;}catch{return false;}};
    const originalFetch=window.fetch.bind(window);
    window.fetch=(input,options={})=>{
        const isRequest=input instanceof Request;
        const url=isRequest?input.url:input;
        const method=options.method||(isRequest?input.method:'GET');
        if(unsafe(method)&&sameOrigin(url)){
            const headers=new Headers(isRequest?input.headers:undefined);
            new Headers(options.headers).forEach((value,name)=>headers.set(name,value));
            if(!headers.has('X-Web-CSRF'))headers.set('X-Web-CSRF',token);
            return originalFetch(input,{...options,headers});
        }
        return originalFetch(input,options);
    };
    const requests=new WeakMap(),xhr=XMLHttpRequest.prototype;
    const open=xhr.open,send=xhr.send,setHeader=xhr.setRequestHeader;
    xhr.open=function(method,url,...rest){requests.set(this,{protect:unsafe(method)&&sameOrigin(url),hasToken:false});return open.call(this,method,url,...rest);};
    xhr.setRequestHeader=function(name,value){if(String(name).toLowerCase()==='x-web-csrf'){const state=requests.get(this);if(state)state.hasToken=true;}return setHeader.call(this,name,value);};
    xhr.send=function(body){const state=requests.get(this);if(state?.protect&&!state.hasToken)setHeader.call(this,'X-Web-CSRF',token);return send.call(this,body);};
    function protectForm(form){if(!unsafe(form.method)||!sameOrigin(form.action||location.href))return;let input=form.querySelector('input[name="_web_csrf"]');if(!input){input=document.createElement('input');input.type='hidden';input.name='_web_csrf';form.append(input);}input.value=token;}
    // AJAX forms already use the fetch/XHR header. Avoid adding extra JSON fields.
    window.addEventListener('submit',event=>{if(!event.defaultPrevented&&event.target instanceof HTMLFormElement)protectForm(event.target);});
    const submit=HTMLFormElement.prototype.submit;
    HTMLFormElement.prototype.submit=function(){protectForm(this);return submit.call(this);};
})();
