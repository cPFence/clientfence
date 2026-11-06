/**
 * ClientFence: the QR code of a text (the staff two-factor setup, mod_staff_profile.html.twig), as an
 * SVG of squares with no style attributes (the CSP blocks them). Same drawing as palisade's twofactor.js.
 */
import qrcode from 'qrcode-generator';

const SVG = 'http://www.w3.org/2000/svg';

export default function qrSvg(text: string): SVGSVGElement {
  const qr = qrcode(0, 'M');
  qr.addData(text);
  qr.make();
  const count = qr.getModuleCount();
  const margin = 2;
  const size = String(count + margin * 2);
  const svg = document.createElementNS(SVG, 'svg');
  svg.setAttribute('viewBox', `0 0 ${size} ${size}`);
  svg.setAttribute('shape-rendering', 'crispEdges');
  svg.setAttribute('role', 'img');
  const background = document.createElementNS(SVG, 'rect');
  background.setAttribute('width', size);
  background.setAttribute('height', size);
  background.setAttribute('fill', '#fff');
  svg.appendChild(background);
  let d = '';
  for (let row = 0; row < count; row += 1) {
    for (let col = 0; col < count; col += 1) {
      if (qr.isDark(row, col)) {
        d += `M${col + margin} ${row + margin}h1v1h-1z`;
      }
    }
  }
  const path = document.createElementNS(SVG, 'path');
  path.setAttribute('d', d);
  path.setAttribute('fill', '#000');
  svg.appendChild(path);
  return svg;
}
