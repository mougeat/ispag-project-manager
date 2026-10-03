import os,re,sys,json
FUNCS={'__':(0,None,None),'_e':(0,None,None),'esc_html__':(0,None,None),'esc_html_e':(0,None,None),'esc_attr__':(0,None,None),'esc_attr_e':(0,None,None),
 '_x':(0,'ctx',1),'_ex':(0,'ctx',1),'esc_html_x':(0,'ctx',1),'esc_attr_x':(0,'ctx',1),'_n':(0,'plural',1),'_nx':(0,'plural_ctx',3)}
# n_args before domain: __ :1 ; _x :2 (text,ctx) ; _n: 3 (single, plural, number) ; _nx: 4
NARGS={'__':1,'_e':1,'esc_html__':1,'esc_html_e':1,'esc_attr__':1,'esc_attr_e':1,'_x':2,'_ex':2,'esc_html_x':2,'esc_attr_x':2,'_n':3,'_nx':4}
CALL=re.compile(r'(?<![A-Za-z0-9_>:$])('+'|'.join(sorted(NARGS,key=len,reverse=True))+r')\s*\(')
def unq(tok):
    q=tok[0]; b=tok[1:-1]
    if q=="'": return re.sub(r"\\(['\\])",r"\1",b)
    return b.replace('\\"','"').replace('\\n','\n').replace('\\\\','\\')
def parse_args(s,i):
    args=[];depth=0;cur=[];n=len(s)
    while i<n:
        c=s[i]
        if c in '\'"':
            j=i+1
            while j<n and s[j]!=c:
                j+=2 if s[j]=='\\' else 1
            cur.append(s[i:j+1]); i=j+1; continue
        if c in '([{': depth+=1
        elif c in ')]}':
            if depth==0: args.append(''.join(cur).strip()); return args,i
            depth-=1
        elif c==',' and depth==0: args.append(''.join(cur).strip()); cur=[]; i+=1; continue
        cur.append(c); i+=1
    return args,i
def is_lit(a): return len(a)>=2 and a[0] in '\'"' and a[-1]==a[0] and not re.search(r'(?<!\\)'+a[0],a[1:-1].replace('\\'+a[0],'')) 
def extract(root):
    out={}  # (domain,ctx,msgid,plural) -> refs
    for dp,dn,fn in os.walk(root):
        dn[:]=[d for d in dn if d not in ('.git','node_modules','libs','vendor','OLD','old','whatsapp-bridge','languages')]
        for f in fn:
            if not f.endswith('.php'): continue
            p=os.path.join(dp,f); s=open(p,encoding='utf-8',errors='replace').read()
            for m in CALL.finditer(s):
                name=m.group(1); args,_=parse_args(s,m.end()); need=NARGS[name]
                if len(args)<need+1: continue
                dom=args[need]
                if not is_lit(dom): continue
                dom=unq(dom)
                lits=[args[k] for k in range(need) if k!=(2 if name in('_n','_nx') else -1)]
                if name in('_n','_nx'):
                    a0,a1=args[0],args[1]
                    if not(is_lit(a0) and is_lit(a1)): continue
                    ctx=unq(args[3]) if name=='_nx' and is_lit(args[3]) else ''
                    key=(dom,ctx,unq(a0),unq(a1))
                else:
                    if not is_lit(args[0]): continue
                    ctx=unq(args[1]) if name in('_x','_ex','esc_html_x','esc_attr_x') and is_lit(args[1]) else ''
                    key=(dom,ctx,unq(args[0]),'')
                line=s.count('\n',0,m.start())+1
                out.setdefault(key,[]).append(f"{os.path.relpath(p,root)}:{line}")
    return out
if __name__=='__main__':
    allk={}
    for r in sys.argv[1:]:
        e=extract(r); print(r,len(e),'unique',sorted({k[0] for k in e}))
        for k,v in e.items(): allk.setdefault(k,set()).add(os.path.basename(r))
    from collections import Counter
    print(Counter(k[0] for k in allk)); print('plural',sum(1 for k in allk if k[3]),'ctx',sum(1 for k in allk if k[1]))
