interface Props {percent:number;preparing:boolean;label:string;description:string}

export function SendingProgressBar({percent,preparing,label,description}:Props) {
  return <div className="sending-progress-track"><progress className="sending-progress-bar" aria-label={label} aria-valuetext={description} value={percent} max={100}/><span className="sending-progress-track__fill" style={{width:`${percent}%`}} aria-hidden="true"/>{preparing&&<span className="sending-progress-track__waiting" aria-hidden="true"/>}</div>;
}
