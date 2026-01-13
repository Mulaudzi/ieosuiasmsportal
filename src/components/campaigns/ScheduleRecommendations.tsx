import { useState, useEffect } from "react";
import { Button } from "@/components/ui/button";
import { Loader2, Sparkles, Clock, TrendingUp, Calendar } from "lucide-react";
import { cn } from "@/lib/utils";
import { api } from "@/lib/api";

interface OptimalTime {
  day: string;
  day_index: number;
  hour: number;
  success_rate: number;
  message_count: number;
}

interface ScheduleRecommendationsProps {
  onSelectTime?: (date: string, time: string) => void;
  campaignType?: "sms" | "email";
}

export function ScheduleRecommendations({ onSelectTime, campaignType = "sms" }: ScheduleRecommendationsProps) {
  const [loading, setLoading] = useState(true);
  const [recommendations, setRecommendations] = useState<OptimalTime[]>([]);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    loadRecommendations();
  }, [campaignType]);

  const loadRecommendations = async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await api.get<{ recommendations: OptimalTime[]; has_data: boolean }>(
        `/dashboard/schedule-recommendations?type=${campaignType}`
      );
      if (res.success && res.data?.recommendations) {
        setRecommendations(res.data.recommendations);
      } else if (!res.data?.has_data) {
        setError("Not enough delivery data yet. Send more campaigns to see recommendations.");
      }
    } catch (err) {
      setError("Unable to load recommendations");
    } finally {
      setLoading(false);
    }
  };

  const getNextOccurrence = (dayIndex: number, hour: number): { date: string; time: string } => {
    const now = new Date();
    const currentDay = now.getDay(); // 0 = Sunday
    
    // dayIndex is 1-7 where 1 = Sunday, convert to 0-6
    const targetDay = dayIndex - 1;
    
    let daysUntil = targetDay - currentDay;
    if (daysUntil < 0) daysUntil += 7;
    if (daysUntil === 0 && now.getHours() >= hour) daysUntil = 7;
    
    const targetDate = new Date(now);
    targetDate.setDate(now.getDate() + daysUntil);
    
    const date = targetDate.toISOString().split('T')[0];
    const time = `${hour.toString().padStart(2, '0')}:00`;
    
    return { date, time };
  };

  const handleSelectTime = (rec: OptimalTime) => {
    const { date, time } = getNextOccurrence(rec.day_index, rec.hour);
    onSelectTime?.(date, time);
  };

  if (loading) {
    return (
      <div className="rounded-lg border border-border bg-gradient-to-br from-primary/5 to-accent/5 p-4">
        <div className="flex items-center gap-2 text-sm text-muted-foreground">
          <Loader2 className="h-4 w-4 animate-spin" />
          Loading recommendations...
        </div>
      </div>
    );
  }

  if (error || recommendations.length === 0) {
    return (
      <div className="rounded-lg border border-border bg-muted/30 p-4">
        <div className="flex items-center gap-2 text-sm text-muted-foreground">
          <Sparkles className="h-4 w-4" />
          {error || "Send more campaigns to unlock optimal timing recommendations."}
        </div>
      </div>
    );
  }

  return (
    <div className="rounded-lg border border-primary/20 bg-gradient-to-br from-primary/5 to-accent/5 p-4">
      <div className="flex items-center gap-2 mb-3">
        <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10">
          <Sparkles className="h-4 w-4 text-primary" />
        </div>
        <div>
          <p className="font-medium text-foreground">Optimal Send Times</p>
          <p className="text-xs text-muted-foreground">Based on your delivery history</p>
        </div>
      </div>
      
      <div className="grid gap-2 sm:grid-cols-3">
        {recommendations.slice(0, 3).map((rec, index) => (
          <button
            key={`${rec.day}-${rec.hour}`}
            onClick={() => handleSelectTime(rec)}
            className={cn(
              "group flex flex-col items-start rounded-lg border p-3 text-left transition-all hover:border-primary hover:bg-primary/5",
              index === 0 
                ? "border-primary/40 bg-primary/10" 
                : "border-border bg-card"
            )}
          >
            <div className="flex items-center gap-2 w-full justify-between">
              <div className="flex items-center gap-1.5">
                <Calendar className="h-3.5 w-3.5 text-muted-foreground" />
                <span className="font-medium text-foreground">{rec.day}</span>
              </div>
              {index === 0 && (
                <span className="rounded-full bg-primary px-1.5 py-0.5 text-[10px] font-medium text-primary-foreground">
                  Best
                </span>
              )}
            </div>
            <div className="flex items-center gap-1.5 mt-1">
              <Clock className="h-3.5 w-3.5 text-muted-foreground" />
              <span className="text-sm text-foreground">{rec.hour}:00</span>
            </div>
            <div className="flex items-center gap-1.5 mt-1">
              <TrendingUp className="h-3.5 w-3.5 text-success" />
              <span className="text-xs text-success">{rec.success_rate}% success</span>
            </div>
          </button>
        ))}
      </div>
      
      <p className="mt-3 text-xs text-muted-foreground">
        Click to auto-fill schedule with recommended time
      </p>
    </div>
  );
}
