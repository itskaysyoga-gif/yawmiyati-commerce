// Yawmiyati Content Studio templates (Remotion). Photo-based pieces only: they move real product photos and never
// pretend to be a demonstration. Every price, fee, offer and country comes from the facts export in the props; the
// script supplies words only (studio/lib/rules.mjs). Template version is recorded on every content record.
import React from 'react';
import { registerRoot, Composition, Still, AbsoluteFill, Img, Sequence, staticFile, useCurrentFrame, useVideoConfig, interpolate, spring } from 'remotion';
import { loadFont } from '@remotion/fonts';

export const TEMPLATE_VERSION = 'photo-motion@1';
const C = { ivory: '#FAF6F1', paper: '#FFFFFF', ink: '#2A2228', muted: '#6E6268', berry: '#7B2D58', blush: '#F7E7E4', sage: '#E7EDE4', sageInk: '#4C6448', rule: '#EEE6E0' };
const FONT = 'Readex Pro';
for (const [w, f] of [[400, 'arabic-400'], [500, 'arabic-500'], [700, 'arabic-700']]) {
  loadFont({ family: FONT, url: staticFile(`fonts/readex-pro-${f}-normal.woff2`), weight: String(w), unicodeRange: 'U+0600-06FF, U+0750-077F, U+0870-088E, U+0890-0891, U+0898-08E1, U+08E3-08FF, U+200C-200E, U+2010-2011, U+204F, U+2E41, U+FB50-FDFF, U+FE70-FE74, U+FE76-FEFC' });
}
for (const [w, f] of [[400, 'latin-400'], [700, 'latin-700']]) loadFont({ family: FONT, url: staticFile(`fonts/readex-pro-${f}-normal.woff2`), weight: String(w), unicodeRange: 'U+0000-00FF' });

// Stills (carousel, story) render frame 0: motion is switched off there so nothing is caught mid-fade.
const StillCtx = React.createContext(false);
const FPS = 30;
const HOOK = 75; // 2.5 s
const BEAT = 75;
const OFFER = 150; // 5 s
export const reelFrames = (p) => HOOK + BEAT * p.angle.beats.length + OFFER;

const Base = ({ children, w }) => (
  <AbsoluteFill lang="ar" dir="rtl" style={{ background: C.ivory, direction: 'rtl', fontFamily: FONT, color: C.ink }}>
    {children}
  </AbsoluteFill>
);

const Draft = ({ show }) => show ? (
  <div style={{ position: 'absolute', top: 36, left: 36, padding: '8px 16px', borderRadius: 10, background: 'rgba(42,34,40,.78)', color: '#fff', fontSize: 24, fontWeight: 500 }}>مسودة داخلية · صور مؤقتة غير معتمدة</div>
) : null;

const Logo = ({ top = 120 }) => <Img src={staticFile('logo.svg')} style={{ position: 'absolute', top, right: 64, height: 78 }} />;

// One product photo in a white card, slowly moving (Ken Burns). Never cropped to change what the product is:
// the card shows the whole photo (contain) and only the motion scales it a little.
const Photo = ({ src, from = 0, dur, box }) => {
  const still = React.useContext(StillCtx);
  const cur = useCurrentFrame();
  const f = still ? 8 : cur - from;
  const s = interpolate(f, [0, dur], [1, 1.06], { extrapolateRight: 'clamp' });
  const x = interpolate(f, [0, dur], [0, -12], { extrapolateRight: 'clamp' });
  const o = interpolate(f, [0, 8], [0, 1], { extrapolateRight: 'clamp' });
  return (
    <div style={{ position: 'absolute', ...box, background: C.paper, borderRadius: 36, border: `2px solid ${C.rule}`, overflow: 'hidden', boxShadow: '0 24px 60px rgba(123,45,88,.10)', opacity: o }}>
      <Img src={staticFile(src)} style={{ width: '100%', height: '100%', objectFit: 'contain', transform: `scale(${s}) translateX(${x}px)` }} />
    </div>
  );
};

const Rise = ({ children, delay = 0, style }) => {
  const still = React.useContext(StillCtx);
  const f = useCurrentFrame();
  const { fps } = useVideoConfig();
  const v = still ? 1 : spring({ frame: f - delay, fps, config: { damping: 200 } });
  return <div style={{ ...style, opacity: v, transform: `translateY(${(1 - v) * 40}px)` }}>{children}</div>;
};

const Offer = ({ p, compact }) => {
  const fx = p.facts;
  return (
    <AbsoluteFill style={{ padding: compact ? '90px 70px' : '880px 70px 400px', justifyContent: compact ? 'center' : 'flex-start' }}>
      {!compact && p.images && p.images.length ? <Photo src={p.images[0]} dur={OFFER} box={{ top: 250, right: 250, width: 580, height: 600 }} /> : null}
      <Rise style={{ background: C.paper, borderRadius: 40, border: `2px solid ${C.rule}`, padding: compact ? 44 : 56, display: 'grid', gap: compact ? 18 : 26 }}>
        <div style={{ fontSize: compact ? 44 : 54, fontWeight: 700, lineHeight: 1.35 }}>{fx.name}</div>
        <div style={{ display: 'grid', gap: 12 }}>
          {fx.offers.filter((o) => o.in_stock).map((o) => (
            <div key={o.sku} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', gap: 20, padding: '14px 22px', borderRadius: 20, background: o.default ? C.blush : C.ivory, fontSize: compact ? 34 : 40 }}>
              <span style={{ fontWeight: 500 }}>{o.label}</span>
              <span style={{ fontWeight: 700, color: C.berry, whiteSpace: 'nowrap' }}>{o.price_text}</span>
            </div>
          ))}
        </div>
        <div style={{ fontSize: compact ? 28 : 33, color: C.muted, lineHeight: 1.6 }}>{fx.delivery.text}<br />{p.copy.cod}</div>
        <div style={{ display: 'flex', gap: 16, alignItems: 'center', flexWrap: 'wrap' }}>
          <div style={{ padding: '18px 34px', borderRadius: 24, background: C.berry, color: '#fff', fontSize: compact ? 36 : 42, fontWeight: 700 }}>{p.angle.cta}</div>
          <div style={{ padding: '10px 22px', borderRadius: 20, background: C.sage, color: C.sageInk, fontSize: compact ? 28 : 32, fontWeight: 500 }}>{fx.country_name}</div>
        </div>
      </Rise>
    </AbsoluteFill>
  );
};

const Caption = ({ text, bottom }) => (
  <Rise style={{ position: 'absolute', right: 64, left: 64, bottom, background: C.paper, borderRadius: 32, padding: '30px 38px', boxShadow: '0 12px 40px rgba(42,34,40,.10)', fontSize: 50, fontWeight: 700, lineHeight: 1.45, color: C.berry, textAlign: 'right' }}>{text}</Rise>
);

// 9:16 master, 1080 x 1920. Text stays inside the platform-safe area (top 220 px and bottom 380 px kept clear).
export const Reel = (p) => {
  const imgs = p.images;
  return (
    <Base>
      <Logo />
      <Sequence durationInFrames={HOOK}>
        <Photo src={imgs[0]} dur={HOOK} box={{ top: 660, right: 90, width: 900, height: 860 }} />
        <Rise style={{ position: 'absolute', top: 280, right: 64, left: 64, fontSize: 76, fontWeight: 700, lineHeight: 1.35, textAlign: 'right' }}>{p.hook}</Rise>
      </Sequence>
      {p.angle.beats.map((b, i) => (
        <Sequence key={i} from={HOOK + i * BEAT} durationInFrames={BEAT}>
          <Photo src={imgs[(i + 1) % imgs.length]} dur={BEAT} box={{ top: 300, right: 90, width: 900, height: 980 }} />
          <Caption text={b.line} bottom={430} />
        </Sequence>
      ))}
      <Sequence from={HOOK + BEAT * p.angle.beats.length} durationInFrames={OFFER}>
        <Offer p={p} />
      </Sequence>
      <Draft show={p.draft} />
    </Base>
  );
};

// 4:5 derivative, 1080 x 1350: composed for the feed (photo and text side by side in time), not a crop of the master.
export const Reel45 = (p) => {
  const imgs = p.images;
  return (
    <Base>
      <Sequence durationInFrames={HOOK}>
        <Photo src={imgs[0]} dur={HOOK} box={{ top: 430, right: 200, width: 680, height: 760 }} />
        <Rise style={{ position: 'absolute', top: 110, right: 70, left: 70, fontSize: 66, fontWeight: 700, lineHeight: 1.35 }}>{p.hook}</Rise>
      </Sequence>
      {p.angle.beats.map((b, i) => (
        <Sequence key={i} from={HOOK + i * BEAT} durationInFrames={BEAT}>
          <Photo src={imgs[(i + 1) % imgs.length]} dur={BEAT} box={{ top: 90, right: 200, width: 680, height: 760 }} />
          <Caption text={b.line} bottom={110} />
        </Sequence>
      ))}
      <Sequence from={HOOK + BEAT * p.angle.beats.length} durationInFrames={OFFER}>
        <Offer p={p} compact />
      </Sequence>
      <Draft show={p.draft} />
    </Base>
  );
};

// Carousel slide (4:5) and story frame (9:16): stills.
export const Slide = (p) => {
  const last = p.index === p.slides.length - 1;
  return (
    <StillCtx.Provider value={true}><Base>
      {last ? <Offer p={p} compact /> : (
        <>
          <Photo src={p.images[p.index % p.images.length]} dur={1} box={{ top: 90, right: 160, width: 760, height: 820 }} />
          <div style={{ position: 'absolute', right: 70, left: 70, bottom: 120, fontSize: 58, fontWeight: 700, lineHeight: 1.4, color: C.berry }}>{p.slides[p.index]}</div>
          <div style={{ position: 'absolute', left: 70, bottom: 60, fontSize: 26, color: C.muted }}>{p.index + 1} / {p.slides.length}</div>
        </>
      )}
      <Draft show={p.draft} />
    </Base></StillCtx.Provider>
  );
};

export const Story = (p) => (
  <StillCtx.Provider value={true}><Base>
    <Logo />
    <Offer p={p} />
    <Draft show={p.draft} />
  </Base></StillCtx.Provider>
);

const Root = () => {
  const dflt = { facts: { name: '', offers: [], delivery: { text: '' }, country_name: '' }, angle: { beats: [{ line: '' }], cta: '' }, hook: '', images: [], copy: { cod: '' }, slides: [''], index: 0, draft: true };
  return (
    <>
      <Composition id="Reel" component={Reel} width={1080} height={1920} fps={FPS} durationInFrames={HOOK + BEAT * 3 + OFFER} defaultProps={dflt} calculateMetadata={({ props }) => ({ durationInFrames: reelFrames(props) })} />
      <Composition id="Reel45" component={Reel45} width={1080} height={1350} fps={FPS} durationInFrames={HOOK + BEAT * 3 + OFFER} defaultProps={dflt} calculateMetadata={({ props }) => ({ durationInFrames: reelFrames(props) })} />
      <Still id="Slide" component={Slide} width={1080} height={1350} defaultProps={dflt} />
      <Still id="Story" component={Story} width={1080} height={1920} defaultProps={dflt} />
    </>
  );
};

registerRoot(Root);
