import { useState, useEffect } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Slider } from "@/components/ui/slider";
import { Switch } from "@/components/ui/switch";
import {
  Beaker,
  Crown,
  Loader2,
  TrendingUp,
  Users,
  Check,
  X,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { api, handleApiError } from "@/lib/api";

interface ABTestVariant {
  variant_name: string;
  message_content: string;
  subject?: string;
  recipient_count: number;
  sent_count: number;
  delivered_count: number;
  failed_count: number;
  delivery_rate: number;
  is_winner: boolean;
}

interface ABTestSetupProps {
  enabled: boolean;
  onEnabledChange: (enabled: boolean) => void;
  messageA: string;
  messageB: string;
  subjectA?: string;
  subjectB?: string;
  splitPercent: number;
  onMessageAChange: (message: string) => void;
  onMessageBChange: (message: string) => void;
  onSubjectAChange?: (subject: string) => void;
  onSubjectBChange?: (subject: string) => void;
  onSplitChange: (percent: number) => void;
  campaignType?: "sms" | "email";
  totalRecipients?: number;
}

export function ABTestSetup({
  enabled,
  onEnabledChange,
  messageA,
  messageB,
  subjectA,
  subjectB,
  splitPercent,
  onMessageAChange,
  onMessageBChange,
  onSubjectAChange,
  onSubjectBChange,
  onSplitChange,
  campaignType = "sms",
  totalRecipients = 0,
}: ABTestSetupProps) {
  const recipientsA = Math.round((totalRecipients * splitPercent) / 100);
  const recipientsB = totalRecipients - recipientsA;

  return (
    <div className="space-y-4">
      {/* Toggle */}
      <div className="flex items-center justify-between rounded-lg border border-border p-4">
        <div className="flex items-center gap-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
            <Beaker className="h-5 w-5 text-primary" />
          </div>
          <div>
            <p className="font-medium text-foreground">A/B Testing</p>
            <p className="text-sm text-muted-foreground">
              Test two message variants and find the best performer
            </p>
          </div>
        </div>
        <Switch checked={enabled} onCheckedChange={onEnabledChange} />
      </div>

      {enabled && (
        <div className="space-y-6 rounded-lg border border-primary/20 bg-primary/5 p-4">
          {/* Split Percentage */}
          <div>
            <Label className="text-sm font-medium">Audience Split</Label>
            <p className="text-xs text-muted-foreground mb-3">
              How to divide recipients between variants
            </p>
            <div className="flex items-center gap-4">
              <div className="flex-1">
                <Slider
                  value={[splitPercent]}
                  onValueChange={([value]) => onSplitChange(value)}
                  min={10}
                  max={90}
                  step={5}
                  className="w-full"
                />
              </div>
              <div className="flex items-center gap-2 text-sm font-medium">
                <span className="text-primary">A: {splitPercent}%</span>
                <span className="text-muted-foreground">/</span>
                <span className="text-accent">B: {100 - splitPercent}%</span>
              </div>
            </div>
            {totalRecipients > 0 && (
              <p className="text-xs text-muted-foreground mt-2">
                Variant A: {recipientsA.toLocaleString()} recipients • Variant B: {recipientsB.toLocaleString()} recipients
              </p>
            )}
          </div>

          {/* Variant A */}
          <div className="rounded-lg border border-primary/30 bg-card p-4">
            <div className="flex items-center gap-2 mb-3">
              <div className="flex h-6 w-6 items-center justify-center rounded-full bg-primary text-xs font-bold text-primary-foreground">
                A
              </div>
              <span className="font-medium text-foreground">Variant A</span>
            </div>
            
            {campaignType === "email" && onSubjectAChange && (
              <div className="mb-3">
                <Label htmlFor="subject-a" className="text-sm">Subject Line</Label>
                <Input
                  id="subject-a"
                  value={subjectA || ""}
                  onChange={(e) => onSubjectAChange(e.target.value)}
                  placeholder="Email subject for variant A"
                  className="mt-1"
                />
              </div>
            )}
            
            <div>
              <Label htmlFor="message-a" className="text-sm">Message</Label>
              <Textarea
                id="message-a"
                value={messageA}
                onChange={(e) => onMessageAChange(e.target.value)}
                placeholder={campaignType === "sms" ? "Enter SMS message for variant A" : "Enter email content for variant A"}
                rows={3}
                className="mt-1"
              />
              {campaignType === "sms" && (
                <p className="text-xs text-muted-foreground mt-1">
                  {messageA.length}/160 characters • {Math.ceil(messageA.length / 160) || 1} SMS part(s)
                </p>
              )}
            </div>
          </div>

          {/* Variant B */}
          <div className="rounded-lg border border-accent/30 bg-card p-4">
            <div className="flex items-center gap-2 mb-3">
              <div className="flex h-6 w-6 items-center justify-center rounded-full bg-accent text-xs font-bold text-accent-foreground">
                B
              </div>
              <span className="font-medium text-foreground">Variant B</span>
            </div>
            
            {campaignType === "email" && onSubjectBChange && (
              <div className="mb-3">
                <Label htmlFor="subject-b" className="text-sm">Subject Line</Label>
                <Input
                  id="subject-b"
                  value={subjectB || ""}
                  onChange={(e) => onSubjectBChange(e.target.value)}
                  placeholder="Email subject for variant B"
                  className="mt-1"
                />
              </div>
            )}
            
            <div>
              <Label htmlFor="message-b" className="text-sm">Message</Label>
              <Textarea
                id="message-b"
                value={messageB}
                onChange={(e) => onMessageBChange(e.target.value)}
                placeholder={campaignType === "sms" ? "Enter SMS message for variant B" : "Enter email content for variant B"}
                rows={3}
                className="mt-1"
              />
              {campaignType === "sms" && (
                <p className="text-xs text-muted-foreground mt-1">
                  {messageB.length}/160 characters • {Math.ceil(messageB.length / 160) || 1} SMS part(s)
                </p>
              )}
            </div>
          </div>

          {/* Tips */}
          <div className="rounded-lg bg-muted/50 p-3 text-xs text-muted-foreground">
            <p className="font-medium text-foreground mb-1">💡 A/B Testing Tips</p>
            <ul className="space-y-1 list-disc list-inside">
              <li>Change only one element between variants for accurate results</li>
              <li>Use a 50/50 split for the most statistically significant results</li>
              <li>The winning variant will be automatically determined by delivery rate</li>
            </ul>
          </div>
        </div>
      )}
    </div>
  );
}

interface ABTestResultsProps {
  campaignId: string;
}

export function ABTestResults({ campaignId }: ABTestResultsProps) {
  const [loading, setLoading] = useState(true);
  const [results, setResults] = useState<{
    campaign: { name: string; status: string; ab_winner_variant: string | null };
    variants: ABTestVariant[];
    suggested_winner: string | null;
    winner_delivery_rate: number;
  } | null>(null);

  useEffect(() => {
    loadResults();
  }, [campaignId]);

  const loadResults = async () => {
    setLoading(true);
    try {
      const res = await api.get<typeof results>(`/reports/ab-test-results?campaign_id=${campaignId}`);
      if (res.success && res.data) {
        setResults(res.data);
      }
    } catch (error) {
      handleApiError(error);
    } finally {
      setLoading(false);
    }
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center py-8">
        <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
      </div>
    );
  }

  if (!results || results.variants.length === 0) {
    return (
      <div className="text-center py-8 text-muted-foreground">
        No A/B test results available yet.
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h3 className="font-semibold text-foreground flex items-center gap-2">
          <Beaker className="h-5 w-5 text-primary" />
          A/B Test Results
        </h3>
        {results.suggested_winner && (
          <div className="flex items-center gap-2 rounded-full bg-success/10 px-3 py-1 text-sm text-success">
            <Crown className="h-4 w-4" />
            Winner: Variant {results.suggested_winner}
          </div>
        )}
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        {results.variants.map((variant) => (
          <div
            key={variant.variant_name}
            className={cn(
              "rounded-xl border p-4",
              variant.is_winner || variant.variant_name === results.suggested_winner
                ? "border-success bg-success/5"
                : "border-border"
            )}
          >
            <div className="flex items-center justify-between mb-3">
              <div className="flex items-center gap-2">
                <div className={cn(
                  "flex h-8 w-8 items-center justify-center rounded-full text-sm font-bold",
                  variant.variant_name === "A" 
                    ? "bg-primary text-primary-foreground" 
                    : "bg-accent text-accent-foreground"
                )}>
                  {variant.variant_name}
                </div>
                <span className="font-medium text-foreground">Variant {variant.variant_name}</span>
              </div>
              {(variant.is_winner || variant.variant_name === results.suggested_winner) && (
                <Crown className="h-5 w-5 text-success" />
              )}
            </div>

            {/* Stats Grid */}
            <div className="grid grid-cols-2 gap-3 mb-3">
              <div className="rounded-lg bg-muted/50 p-2 text-center">
                <p className="text-lg font-bold text-foreground">{variant.recipient_count}</p>
                <p className="text-xs text-muted-foreground">Recipients</p>
              </div>
              <div className="rounded-lg bg-muted/50 p-2 text-center">
                <p className="text-lg font-bold text-success">{variant.delivered_count}</p>
                <p className="text-xs text-muted-foreground">Delivered</p>
              </div>
              <div className="rounded-lg bg-muted/50 p-2 text-center">
                <p className="text-lg font-bold text-destructive">{variant.failed_count}</p>
                <p className="text-xs text-muted-foreground">Failed</p>
              </div>
              <div className={cn(
                "rounded-lg p-2 text-center",
                variant.is_winner || variant.variant_name === results.suggested_winner
                  ? "bg-success/20"
                  : "bg-muted/50"
              )}>
                <p className={cn(
                  "text-lg font-bold",
                  variant.is_winner || variant.variant_name === results.suggested_winner
                    ? "text-success"
                    : "text-foreground"
                )}>
                  {variant.delivery_rate}%
                </p>
                <p className="text-xs text-muted-foreground">Delivery Rate</p>
              </div>
            </div>

            {/* Message Preview */}
            {variant.subject && (
              <div className="mb-2">
                <p className="text-xs text-muted-foreground">Subject:</p>
                <p className="text-sm text-foreground">{variant.subject}</p>
              </div>
            )}
            <div>
              <p className="text-xs text-muted-foreground">Message:</p>
              <p className="text-sm text-foreground line-clamp-3">{variant.message_content}</p>
            </div>
          </div>
        ))}
      </div>

      {/* Insight */}
      {results.suggested_winner && results.variants.length === 2 && (
        <div className="rounded-lg border border-success/30 bg-success/5 p-4">
          <div className="flex items-start gap-3">
            <TrendingUp className="h-5 w-5 text-success mt-0.5" />
            <div>
              <p className="font-medium text-foreground">Variant {results.suggested_winner} Wins!</p>
              <p className="text-sm text-muted-foreground">
                Variant {results.suggested_winner} achieved a{" "}
                <span className="text-success font-medium">{results.winner_delivery_rate}%</span> delivery rate,{" "}
                outperforming the other variant by{" "}
                {Math.abs(
                  results.variants[0].delivery_rate - results.variants[1].delivery_rate
                ).toFixed(1)}%.
              </p>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
