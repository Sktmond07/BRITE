<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>403 - Forbidden | Sad Paper</title>
<style>
  * {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
  }

  body {
    margin: 0;
    font-family: 'Segoe UI', 'Quicksand', system-ui, -apple-system, 'Poppins', sans-serif;
    background: linear-gradient(145deg, #b8cfE8 0%, #9bb5d0 100%);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    overflow-x: hidden;
  }

  /* retro-window top bar */
  .topbar {
    height: 38px;
    background: #1f4f9e;
    display: flex;
    align-items: center;
    padding-left: 16px;
    gap: 8px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
    border-bottom: 1px solid #ffffff30;
  }

  .dot {
    width: 12px;
    height: 12px;
    background: #ffffffcc;
    border-radius: 50%;
    transition: all 0.2s;
    box-shadow: inset 0 1px 1px rgba(0,0,0,0.1);
  }
  .dot:nth-child(1) { background: #ff5f56; }
  .dot:nth-child(2) { background: #ffbd2e; }
  .dot:nth-child(3) { background: #27c93f; }

  /* main layout */
  .container {
    flex: 1;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0 10%;
    gap: 3rem;
    flex-wrap: wrap;
  }

  /* LEFT TEXT AREA */
  .text-block {
    z-index: 3;
    backdrop-filter: blur(2px);
  }

  .text-block h1 {
    font-size: 7rem;
    font-weight: 800;
    margin: 0;
    color: #1e2f3c;
    text-shadow: 4px 4px 0 rgba(0,0,0,0.08);
    letter-spacing: -2px;
  }

  .text-block .sub {
    font-size: 1.8rem;
    font-weight: 500;
    color: #2c3e44;
    margin-top: 0.25rem;
    border-left: 4px solid #ff8a5c;
    padding-left: 1rem;
  }

  .text-block .message {
    margin-top: 1.5rem;
    color: #1f2e36;
    max-width: 380px;
    font-weight: 500;
    background: rgba(255,255,240,0.2);
    padding: 0.8rem 1rem;
    border-radius: 32px;
    backdrop-filter: blur(4px);
  }

  /* PAPER CHARACTER — enhanced with expressive features & animation */
  .paper-character {
    position: relative;
    width: 180px;
    height: 220px;
    background: #fef9e8;
    border-radius: 12px 12px 8px 8px;
    box-shadow: 12px 16px 0 rgba(0,0,0,0.12), 0 8px 20px rgba(0,0,0,0.2);
    transition: all 0.1s ease;
    cursor: pointer;
    transform-origin: center center;
    animation: paperFloat 2.8s infinite ease-in-out;
  }

  /* folded corner (realistic) */
  .paper-character::before {
    content: "";
    position: absolute;
    top: 0;
    right: 0;
    width: 32px;
    height: 32px;
    background: linear-gradient(135deg, #f3ebd2 0%, #e5dcc2 100%);
    clip-path: polygon(0 0, 100% 0, 100% 100%);
    border-radius: 0 8px 0 0;
    box-shadow: -2px 2px 5px rgba(0,0,0,0.05);
  }

  /* fold line */
  .paper-character::after {
    content: "";
    position: absolute;
    top: 0;
    right: 0;
    width: 32px;
    height: 32px;
    background: transparent;
    box-shadow: -1px 1px 0 rgba(0,0,0,0.05);
    pointer-events: none;
  }

  /* face container */
  .face {
    position: relative;
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
  }

  /* eyes group - animated & movable */
  .eyes-group {
    display: flex;
    gap: 32px;
    justify-content: center;
    align-items: center;
    margin-top: 50px;
    margin-bottom: 16px;
  }

  .eye {
    position: relative;
    width: 22px;
    height: 28px;
    background: white;
    border-radius: 50%;
    box-shadow: inset 0 0 0 2px #2b2b2b, 0 2px 6px rgba(0,0,0,0.1);
    overflow: hidden;
  }

  /* pupil that can move + blink effect */
  .pupil {
    position: absolute;
    width: 12px;
    height: 12px;
    background: #1f2a2e;
    border-radius: 50%;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    transition: all 0.08s linear;
  }

  .pupil::after {
    content: "";
    position: absolute;
    width: 4px;
    height: 4px;
    background: white;
    border-radius: 50%;
    top: 2px;
    right: 2px;
    opacity: 0.8;
  }

  /* sad eyebrows */
  .brow {
    position: absolute;
    width: 26px;
    height: 4px;
    background: #2c2c2c;
    border-radius: 20px;
    top: -10px;
    transition: transform 0.2s ease;
  }

  .left-brow {
    left: -2px;
    transform: rotate(12deg) translateY(2px);
  }

  .right-brow {
    right: -2px;
    transform: rotate(-12deg) translateY(2px);
  }

  /* mouth container */
  .mouth-area {
    margin-top: 5px;
    position: relative;
    display: flex;
    justify-content: center;
  }

  .sad-mouth {
    width: 42px;
    height: 26px;
    border: 3px solid #2d2f36;
    border-top: none;
    border-radius: 0 0 36px 36px;
    transform: rotate(2deg);
    transition: all 0.2s;
    background: transparent;
  }

  /* quivering / trembling effect when crying */
  .shake-active {
    animation: paperShake 0.28s infinite !important;
  }

  /* tear drops styling */
  .tear-drop {
    position: absolute;
    background: radial-gradient(circle, #8ad0ff, #3a9eff);
    width: 10px;
    height: 14px;
    border-radius: 50% 50% 50% 50% / 60% 60% 40% 40%;
    filter: drop-shadow(0 2px 2px rgba(0,0,0,0.1));
    pointer-events: none;
    z-index: 15;
  }

  /* blush marks (extra sad cuteness) */
  .blush {
    position: absolute;
    width: 20px;
    height: 12px;
    background: #ffb7b7;
    border-radius: 50%;
    filter: blur(3px);
    opacity: 0.55;
    bottom: 40px;
  }

  .blush-left {
    left: 20px;
  }
  .blush-right {
    right: 20px;
  }

  /* animations */
  @keyframes paperFloat {
    0% { transform: translateY(0px) rotate(-2deg); }
    50% { transform: translateY(-12px) rotate(1deg); }
    100% { transform: translateY(0px) rotate(-2deg); }
  }

  @keyframes paperShake {
    0% { transform: translate(0px, 0px) rotate(-2deg); }
    20% { transform: translate(1.5px, -1px) rotate(-1deg); }
    40% { transform: translate(-1.2px, 0.8px) rotate(-3deg); }
    60% { transform: translate(1px, 1px) rotate(0deg); }
    80% { transform: translate(-1px, -0.5px) rotate(-2deg); }
    100% { transform: translate(0,0) rotate(-2deg); }
  }

  @keyframes tearFall {
    0% {
      transform: translateY(0) scale(0.7);
      opacity: 0.9;
    }
    40% {
      opacity: 1;
    }
    100% {
      transform: translateY(75px) translateX(6px) scale(0.9);
      opacity: 0;
    }
  }

  /* eyebrow twitch animation for sad */
  @keyframes browWobble {
    0% { transform: rotate(12deg) translateY(2px);}
    50% { transform: rotate(8deg) translateY(4px);}
    100% { transform: rotate(12deg) translateY(2px);}
  }

  .sad-brow-anim .left-brow {
    animation: browWobble 1.2s infinite ease;
  }
  .sad-brow-anim .right-brow {
    animation: browWobble 1.2s infinite reverse ease;
  }

  /* responsive */
  @media (max-width: 780px) {
    .container {
      flex-direction: column;
      justify-content: center;
      padding: 2rem;
    }
    .text-block h1 { font-size: 4.5rem; }
    .text-block .sub { font-size: 1.4rem; }
    .paper-character { width: 150px; height: 190px; }
    .eyes-group { gap: 24px; margin-top: 40px; }
  }
</style>
</head>
<body>

<div class="topbar">
  <div class="dot"></div>
  <div class="dot"></div>
  <div class="dot"></div>
  <span style="margin-left: 12px; color: white; font-size: 13px; font-weight: 500;">access denied</span>
</div>

<div class="container">
  <div class="text-block">
    <h1>403</h1>
    <div class="sub">Forbidden</div>
    <div class="message">
      ⚡ You don't have permission.<br>
      The paper is sad ... maybe ask nicely?
    </div>
  </div>

  <!-- animated paper character with moving eyes, crying, blinking -->
  <div class="paper-character" id="sadPaper">
    <div class="face">
      <div class="eyes-group" id="eyesGroup">
        <div class="eye" id="leftEye">
          <div class="pupil" id="leftPupil"></div>
          <div class="brow left-brow" id="leftBrow"></div>
        </div>
        <div class="eye" id="rightEye">
          <div class="pupil" id="rightPupil"></div>
          <div class="brow right-brow" id="rightBrow"></div>
        </div>
      </div>
      <div class="mouth-area">
        <div class="sad-mouth" id="sadMouth"></div>
      </div>
      <!-- blush for extra sad cuteness -->
      <div class="blush blush-left"></div>
      <div class="blush blush-right"></div>
    </div>
  </div>
</div>

<script>
  (function() {
    const paper = document.getElementById('sadPaper');
    const leftPupil = document.getElementById('leftPupil');
    const rightPupil = document.getElementById('rightPupil');
    const leftEyeDiv = document.getElementById('leftEye');
    const rightEyeDiv = document.getElementById('rightEye');
    const mouth = document.getElementById('sadMouth');
    
    // state for blink & tear intervals
    let tearInterval = null;
    let isCrying = false;
    let blinkInterval = null;

    // ---- 1. MOVING EYES (follows mouse / slight motion to give life) ----
    function moveEyes(e) {
      // get eye rectangles
      const leftRect = leftEyeDiv.getBoundingClientRect();
      const rightRect = rightEyeDiv.getBoundingClientRect();
      
      let mouseX, mouseY;
      if (e && (e.clientX !== undefined)) {
        mouseX = e.clientX;
        mouseY = e.clientY;
      } else {
        // fallback: if no event, do nothing
        return;
      }
      
      // for left eye: compute relative offset
      const leftEyeCenterX = leftRect.left + leftRect.width / 2;
      const leftEyeCenterY = leftRect.top + leftRect.height / 2;
      const rightEyeCenterX = rightRect.left + rightRect.width / 2;
      const rightEyeCenterY = rightRect.top + rightRect.height / 2;
      
      // delta between mouse and eye center
      let leftDeltaX = mouseX - leftEyeCenterX;
      let leftDeltaY = mouseY - leftEyeCenterY;
      let rightDeltaX = mouseX - rightEyeCenterX;
      let rightDeltaY = mouseY - rightEyeCenterY;
      
      // limit movement radius (pupil max shift 5px inside eye)
      const maxShift = 5;
      const leftDistance = Math.min(maxShift, Math.hypot(leftDeltaX, leftDeltaY) / 12);
      const rightDistance = Math.min(maxShift, Math.hypot(rightDeltaX, rightDeltaY) / 12);
      
      let leftAngle = Math.atan2(leftDeltaY, leftDeltaX);
      let rightAngle = Math.atan2(rightDeltaY, rightDeltaX);
      
      let leftOffsetX = Math.cos(leftAngle) * leftDistance;
      let leftOffsetY = Math.sin(leftAngle) * leftDistance;
      let rightOffsetX = Math.cos(rightAngle) * rightDistance;
      let rightOffsetY = Math.sin(rightAngle) * rightDistance;
      
      // apply transforms with smooth movement
      leftPupil.style.transform = `translate(calc(-50% + ${leftOffsetX}px), calc(-50% + ${leftOffsetY}px))`;
      rightPupil.style.transform = `translate(calc(-50% + ${rightOffsetX}px), calc(-50% + ${rightOffsetY}px))`;
    }
    
    // reset pupils to center when mouse leaves window
    function resetPupils() {
      leftPupil.style.transform = `translate(-50%, -50%)`;
      rightPupil.style.transform = `translate(-50%, -50%)`;
    }
    
    // add mouse move listener
    window.addEventListener('mousemove', (e) => {
      moveEyes(e);
    });
    window.addEventListener('mouseleave', () => {
      resetPupils();
    });
    
    // ---- 2. BLINKING (natural eye closure) with sad expression ----
    function blink() {
      // store original pupils display
      const leftOrig = leftPupil.style.transform;
      const rightOrig = rightPupil.style.transform;
      // hide pupils temporarily + add eyelid effect (cover with white/ semi)
      leftPupil.style.opacity = '0';
      rightPupil.style.opacity = '0';
      // optional: add a "lid" effect using pseudo? but simpler: scale eyes
      leftEyeDiv.style.overflow = 'visible';
      rightEyeDiv.style.overflow = 'visible';
      // we can create small blink line but opacity works nicely
      setTimeout(() => {
        leftPupil.style.opacity = '1';
        rightPupil.style.opacity = '1';
        // restore movement offsets
        if (window.lastMouseEvent) {
          moveEyes(window.lastMouseEvent);
        } else {
          resetPupils();
        }
      }, 120);
    }
    
    // periodic blink every 2 to 4 seconds
    function startBlinking() {
      if (blinkInterval) clearInterval(blinkInterval);
      blinkInterval = setInterval(() => {
        blink();
      }, 2800);
    }
    
    // record last mouse event for restoration after blink
    let lastMouseEvent = null;
    window.addEventListener('mousemove', (e) => {
      lastMouseEvent = e;
      moveEyes(e);
    });
    
    // ---- 3. SAD MOUTH TWITCH + subtle frown animation ----
    function animateMouth() {
      mouth.style.transition = 'all 0.2s cubic-bezier(0.68, -0.55, 0.27, 1.55)';
      setInterval(() => {
        if (!isCrying) {
          // occasional tiny quiver: mouth slightly curves more
          mouth.style.transform = 'rotate(1deg) scaleY(0.98)';
          setTimeout(() => {
            mouth.style.transform = 'rotate(2deg) scaleY(1)';
          }, 150);
        } else {
          // when crying, more dramatic trembling
          mouth.style.transform = 'rotate(3deg) translateY(1px)';
          setTimeout(() => {
            mouth.style.transform = 'rotate(0deg) translateY(0px)';
          }, 200);
        }
      }, 1100);
    }
    
    // ---- 4. ADD TEARS (CRYING) & shaking effect ----
    function createTear(eyeElement, offsetX = 0, offsetYBase = 12) {
      const tear = document.createElement('div');
      tear.className = 'tear-drop';
      const eyeRect = eyeElement.getBoundingClientRect();
      const paperRect = paper.getBoundingClientRect();
      // relative position inside paper
      const relativeLeft = eyeRect.left + eyeRect.width/2 - paperRect.left + (offsetX * 2);
      const relativeTop = eyeRect.top + eyeRect.height/2 - paperRect.top + offsetYBase;
      tear.style.left = `${relativeLeft - 4}px`;
      tear.style.top = `${relativeTop}px`;
      tear.style.position = 'absolute';
      tear.style.width = '8px';
      tear.style.height = '13px';
      tear.style.animation = 'tearFall 1.2s ease-out forwards';
      paper.appendChild(tear);
      setTimeout(() => {
        if (tear && tear.remove) tear.remove();
      }, 1200);
    }
    
    // start crying sequence: tears from both eyes, plus shaking
    function startCrying() {
      if (isCrying) return;
      isCrying = true;
      // add shaking class
      paper.classList.add('shake-active');
      // add extra eyebrow sad wobble class
      paper.classList.add('sad-brow-anim');
      
      // tear loop: every 350ms produce tears from left and right eye positions
      if (tearInterval) clearInterval(tearInterval);
      tearInterval = setInterval(() => {
        if (!paper.isConnected) return;
        const leftEyeRect = leftEyeDiv.getBoundingClientRect();
        const rightEyeRect = rightEyeDiv.getBoundingClientRect();
        const paperRect = paper.getBoundingClientRect();
        if (paperRect) {
          // left tear
          const leftTearX = leftEyeRect.left + leftEyeRect.width/2 - paperRect.left - 4;
          const leftTearY = leftEyeRect.top + leftEyeRect.height/2 - paperRect.top + 8;
          const rightTearX = rightEyeRect.left + rightEyeRect.width/2 - paperRect.left - 4;
          const rightTearY = rightEyeRect.top + rightEyeRect.height/2 - paperRect.top + 8;
          
          const tearLeft = document.createElement('div');
          tearLeft.className = 'tear-drop';
          tearLeft.style.left = `${leftTearX}px`;
          tearLeft.style.top = `${leftTearY}px`;
          tearLeft.style.animation = 'tearFall 1.1s ease-out forwards';
          paper.appendChild(tearLeft);
          setTimeout(() => tearLeft.remove(), 1100);
          
          const tearRight = document.createElement('div');
          tearRight.className = 'tear-drop';
          tearRight.style.left = `${rightTearX}px`;
          tearRight.style.top = `${rightTearY}px`;
          tearRight.style.animation = 'tearFall 1.1s ease-out forwards';
          paper.appendChild(tearRight);
          setTimeout(() => tearRight.remove(), 1100);
          
          // sometimes extra droplet (more dramatic)
          if (Math.random() > 0.6) {
            const extraTear = document.createElement('div');
            extraTear.className = 'tear-drop';
            extraTear.style.left = `${leftTearX - 3}px`;
            extraTear.style.top = `${leftTearY + 3}px`;
            extraTear.style.width = '6px';
            extraTear.style.height = '10px';
            extraTear.style.animation = 'tearFall 1s ease-out forwards';
            paper.appendChild(extraTear);
            setTimeout(() => extraTear.remove(), 1000);
          }
        }
      }, 330);
    }
    
    // ---- 5. start crying after 1.5 seconds, but also make eyebrows & eyes extra sad ----
    setTimeout(() => {
      startCrying();
      // extra effect: make pupils slightly smaller for sad puppy look
      leftPupil.style.transform = `translate(-50%, -50%) scale(0.85)`;
      rightPupil.style.transform = `translate(-50%, -50%) scale(0.85)`;
      // also darken pupils? add subtle shadow
      leftPupil.style.background = '#11151a';
      rightPupil.style.background = '#11151a';
    }, 1500);
    
    // blink starts immediately
    startBlinking();
    
    // mouth quivering / sad twitching
    animateMouth();
    
    // optional: add hover interaction — when hover on paper, more tears? not needed but sad reacts
    paper.addEventListener('mouseenter', () => {
      if (!isCrying) startCrying();
      else {
        // increase tear rate temporarily: add one immediate burst
        for(let i=0;i<2;i++){
          setTimeout(()=>{
            if(paper.isConnected){
              const leftRect = leftEyeDiv.getBoundingClientRect();
              const rightRect = rightEyeDiv.getBoundingClientRect();
              const paperRect = paper.getBoundingClientRect();
              if(paperRect){
                const tearX = leftRect.left + leftRect.width/2 - paperRect.left - 4;
                const tearY = leftRect.top + leftRect.height/2 - paperRect.top + 8;
                const t = document.createElement('div');
                t.className = 'tear-drop';
                t.style.left = `${tearX}px`;
                t.style.top = `${tearY}px`;
                t.style.animation = 'tearFall 0.9s ease-out forwards';
                paper.appendChild(t);
                setTimeout(()=>t.remove(),900);
              }
            }
          }, i*80);
        }
      }
    });
    
    // rotate sad eyebrows slightly more (dynamic)
    const leftBrowElem = document.getElementById('leftBrow');
    const rightBrowElem = document.getElementById('rightBrow');
    function intensifySadEyebrows() {
      if (leftBrowElem && rightBrowElem) {
        leftBrowElem.style.transform = 'rotate(18deg) translateY(1px)';
        rightBrowElem.style.transform = 'rotate(-18deg) translateY(1px)';
        leftBrowElem.style.transition = 'transform 0.3s';
        rightBrowElem.style.transition = 'transform 0.3s';
      }
    }
    setTimeout(intensifySadEyebrows, 1800);
    
    // make paper interactive: slight sympathy effect
    paper.style.cursor = 'pointer';
    paper.addEventListener('click', () => {
      // blink rapidly + whimper effect (add a few tears)
      blink();
      if(isCrying){
        for(let i=0;i<3;i++){
          setTimeout(()=>{
            const rectL = leftEyeDiv.getBoundingClientRect();
            const rectR = rightEyeDiv.getBoundingClientRect();
            const pRect = paper.getBoundingClientRect();
            if(pRect){
              const tearAdd = document.createElement('div');
              tearAdd.className = 'tear-drop';
              tearAdd.style.left = `${rectL.left+rectL.width/2 - pRect.left - 4}px`;
              tearAdd.style.top = `${rectL.top+rectL.height/2 - pRect.top + 9}px`;
              tearAdd.style.animation = 'tearFall 0.9s forwards';
              paper.appendChild(tearAdd);
              setTimeout(()=>tearAdd.remove(),900);
            }
          }, i*60);
        }
      }
    });
    
    // make sure pupil movement is consistent after any blink override
    window.addEventListener('resize', () => {
      if (lastMouseEvent) moveEyes(lastMouseEvent);
    });
  })();
</script>
</body>
</html>